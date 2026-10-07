<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_caregivertraining;

use core_external\external_api;
use local_caregivertraining\external\v1_get_binding;
use local_caregivertraining\external\v1_health;
use local_caregivertraining\external\v1_provision_learner;
use local_caregivertraining\external\v1_reconcile;
use local_caregivertraining\external\v1_record_heartbeat;
use local_caregivertraining\external\v1_update_access;
use local_caregivertraining\external\v1_upsert_cycle;
use local_caregivertraining\local\config;

/**
 * Versioned external functions: capability checks, idempotency and return structures.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(v1_provision_learner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(v1_upsert_cycle::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\idempotency::class)]
final class external_test extends \advanced_testcase {
    /** @var \stdClass */
    private $adapter;
    /** @var \stdClass */
    private $fixture;

    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/webservice/tests/helpers.php');
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fixture = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_annual_course();
        $this->adapter = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'cgtadapter']);
        assign_capability('local/caregivertraining:adapterapi', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $this->adapter->id, \context_system::instance());
    }

    /**
     * Provision through the external API.
     *
     * @param string $key
     * @param array $overrides
     * @return array
     */
    private function provision(string $key, array $overrides = []): array {
        $p = $overrides + ['alayacareid' => '5005', 'email' => 'riley@example.com', 'firstname' => 'Riley',
            'lastname' => 'Synthetic', 'payrollnumber' => 'PR-5005'];
        $result = v1_provision_learner::execute(
            $key,
            $p['alayacareid'],
            0,
            $p['email'],
            $p['firstname'],
            $p['lastname'],
            $p['payrollnumber'],
            null,
            null,
            true,
            false
        );
        return external_api::clean_returnvalue(v1_provision_learner::execute_returns(), $result);
    }

    public function test_functions_require_the_adapter_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $calls = [
            fn() => v1_get_binding::execute('5005'),
            fn() => v1_health::execute(),
            fn() => v1_reconcile::execute(),
            fn() => $this->provision('k1'),
            fn() => v1_upsert_cycle::execute('k2', 'C-1', 2, '5005', '', '2026-12-31'),
            fn() => v1_update_access::execute('k3', 'C-1', 2, '5005', 'active'),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("call {$i} allowed without capability");
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
                $this->assertStringContainsString(
                    get_capability_string('local/caregivertraining:adapterapi'),
                    $e->getMessage()
                );
            }
        }
    }

    public function test_capability_is_not_granted_by_default_archetypes(): void {
        global $DB;
        foreach (['manager', 'editingteacher', 'student', 'user'] as $archetype) {
            $role = $DB->get_record('role', ['archetype' => $archetype], '*', IGNORE_MULTIPLE);
            $this->assertFalse($DB->record_exists('role_capabilities', ['roleid' => $role->id,
                'capability' => 'local/caregivertraining:adapterapi', 'permission' => CAP_ALLOW]), $archetype);
        }
    }

    public function test_idempotent_replay_and_key_reuse_conflict(): void {
        global $DB;
        $this->setUser($this->adapter);
        $first = $this->provision('prov-5005-1');
        $this->assertSame('created', $first['status']);
        $this->assertFalse($first['replayed']);
        $again = $this->provision('prov-5005-1');
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['userid'], $again['userid']);
        $this->assertEquals(1, $DB->count_records('user', ['email' => 'riley@example.com']));

        try {
            $this->provision('prov-5005-1', ['firstname' => 'Changed']);
            $this->fail('key reuse with a different request must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:idempotencyconflict', $e->errorcode);
        }
        $this->expectException(\invalid_parameter_exception::class);
        $this->provision('bad key with spaces');
    }

    public function test_cycle_upsert_and_reconcile_round_trip(): void {
        $this->setUser($this->adapter);
        $learner = $this->provision('prov-1');
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        $result = external_api::clean_returnvalue(
            v1_upsert_cycle::execute_returns(),
            v1_upsert_cycle::execute('cyc-1', 'CYC-5005-2026', $learner['userid'], '5005', '2020-02-01', $anniversary)
        );
        $this->assertSame('created', $result['action']);
        $this->assertSame('open', $result['cycle']['status']);
        $replay = v1_upsert_cycle::execute(
            'cyc-1',
            'CYC-5005-2026',
            $learner['userid'],
            '5005',
            '2020-02-01',
            $anniversary
        );
        $this->assertTrue($replay['replayed']);

        $reconciled = external_api::clean_returnvalue(v1_reconcile::execute_returns(), v1_reconcile::execute(['CYC-5005-2026']));
        $this->assertCount(1, $reconciled['cycles']);
        $this->assertSame('CYC-5005-2026', $reconciled['cycles'][0]['cycleid']);
        $this->assertContains('time_policy_unresolved', $reconciled['cycles'][0]['gates']);

        $health = external_api::clean_returnvalue(v1_health::execute_returns(), v1_health::execute());
        $this->assertSame('v1', $health['contract']);
        $this->assertContains('time_policy_unresolved', $health['problems']);
        $this->assertFalse($health['adapterdelivery']);
    }

    public function test_heartbeat_requires_enrolled_learner_in_annual_course(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $learner = $gen->create_learner();
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        local\cycle_manager::upsert(['cycleid' => 'HB-1', 'userid' => $learner->user->id,
            'alayacareid' => $learner->alayacareid, 'anniversarydate' => $anniversary]);

        $this->setUser($learner->user);
        $result = external_api::clean_returnvalue(
            v1_record_heartbeat::execute_returns(),
            v1_record_heartbeat::execute((int) $this->fixture->lesson->cmid, str_repeat('ab', 16), true, false, 1)
        );
        $this->assertSame('session_started', $result['reason']);

        $othercourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $othercourse->id]);
        $this->getDataGenerator()->enrol_user($learner->user->id, $othercourse->id);
        try {
            v1_record_heartbeat::execute((int) $page->cmid, str_repeat('cd', 16), true, false, 1);
            $this->fail('heartbeats outside the annual course must be rejected');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('annual course', $e->getMessage());
        }

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        v1_record_heartbeat::execute((int) $this->fixture->lesson->cmid, str_repeat('ef', 16), true, false, 1);
    }
}
