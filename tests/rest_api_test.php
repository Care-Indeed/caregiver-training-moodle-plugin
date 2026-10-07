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

use local_caregivertraining\local\config;
use local_caregivertraining\local\rest_api;

/**
 * REST routes, status codes, error bodies and bearer-token authentication.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rest_api::class)]
final class rest_api_test extends \advanced_testcase {
    /** @var \stdClass */
    private $adapter;
    /** @var \stdClass */
    private $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->fixture = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_annual_course();
        $this->adapter = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'cgtadapter']);
        assign_capability('local/caregivertraining:adapterapi', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $this->adapter->id, \context_system::instance());
        $this->setUser($this->adapter);
    }

    /**
     * Send a request as the current user.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body encoded as JSON
     * @param string $key Idempotency-Key header
     * @param array $query
     * @return array
     */
    private function request(string $method, string $path, ?array $body = null, string $key = '', array $query = []): array {
        $headers = $key !== '' ? ['idempotency-key' => $key] : [];
        return rest_api::handle($method, $path, $query, $body === null ? '' : json_encode($body), $headers);
    }

    /**
     * Provision the standard test learner.
     *
     * @param string $key
     * @param array $overrides
     * @return array
     */
    private function put_learner(string $key = 'prov-1', array $overrides = []): array {
        return $this->request('PUT', '/v1/learners/5005', $overrides + ['email' => 'riley@example.com',
            'firstname' => 'Riley', 'lastname' => 'Synthetic', 'payrollnumber' => 'PR-5005', 'createifmissing' => true], $key);
    }

    public function test_unknown_route_and_wrong_method(): void {
        $response = $this->request('GET', '/v1/nothing');
        $this->assertSame(404, $response['status']);
        $this->assertSame(['code' => 404, 'error' => 'routenotfound', 'message' => 'No endpoint matches GET /v1/nothing.'],
            $response['body']);

        $response = $this->request('DELETE', '/v1/cycles/C-1');
        $this->assertSame(405, $response['status']);
        $this->assertSame('GET, PUT', $response['headers']['Allow']);
    }

    public function test_health(): void {
        $response = $this->request('GET', '/v1/health');
        $this->assertSame(200, $response['status']);
        $this->assertSame('v1', $response['body']['contract']);

        $response = $this->request('GET', '/v1/health', null, '', ['verbose' => '1']);
        $this->assertSame(400, $response['status']);
        $this->assertSame('invalidparameter', $response['body']['error']);
        $this->assertStringContainsString('Unexpected field(s): verbose', $response['body']['details']);
    }

    public function test_provision_create_replay_lookup_and_not_found(): void {
        $created = $this->put_learner();
        $this->assertSame(201, $created['status']);
        $this->assertSame('created', $created['body']['status']);
        $this->assertStringEndsWith('/local/caregivertraining/api.php/v1/learners/5005', $created['headers']['Location']);

        $replay = $this->put_learner();
        $this->assertTrue($replay['body']['replayed']);
        $this->assertSame($created['body']['userid'], $replay['body']['userid']);

        $unchanged = $this->put_learner('prov-2');
        $this->assertSame(200, $unchanged['status']);
        $this->assertSame('unchanged', $unchanged['body']['status']);

        $learner = $this->request('GET', '/v1/learners/5005');
        $this->assertSame(200, $learner['status']);
        $this->assertSame('PR-5005', $learner['body']['payrollnumber']);
        $this->assertArrayNotHasKey('externalid', $learner['body']);
        $this->assertArrayNotHasKey('matchedby', $learner['body']);

        $search = $this->request('GET', '/v1/learners', null, '', ['payrollnumber' => 'PR-5005']);
        $this->assertSame('bound', $search['body']['status']);
        $this->assertSame(1, $search['body']['count']);

        $missing = $this->request('GET', '/v1/learners/9999');
        $this->assertSame(404, $missing['status']);
        $this->assertSame('learnernotfound', $missing['body']['error']);

        $notcreated = $this->request('PUT', '/v1/learners/9999', ['email' => 'nobody@example.com'], 'prov-3');
        $this->assertSame(404, $notcreated['status']);
        $this->assertSame('learnernotfound', $notcreated['body']['error']);
        $this->assertStringContainsString('createifmissing', $notcreated['body']['details']);
    }

    public function test_provision_errors(): void {
        $response = $this->put_learner('');
        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('Idempotency-Key', $response['body']['details']);

        $response = rest_api::handle('PUT', '/v1/learners/5005', [], '{not json', ['idempotency-key' => 'k']);
        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('not valid JSON', $response['body']['details']);

        $response = $this->put_learner('k1', ['alayacareid' => '6006']);
        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('does not match the URL', $response['body']['details']);

        $this->getDataGenerator()->create_user(['email' => 'taken@example.com']);
        $response = $this->put_learner('k2', ['email' => 'taken@example.com']);
        $this->assertSame(409, $response['status']);
        $this->assertSame('existingaccount', $response['body']['error']);

        $this->assertSame(201, $this->put_learner('k3')['status']);
        $response = $this->put_learner('k3', ['firstname' => 'Changed']);
        $this->assertSame(409, $response['status']);
        $this->assertSame('idempotencyconflict', $response['body']['error']);
    }

    public function test_cycle_routes(): void {
        $userid = $this->put_learner()['body']['userid'];
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        $cycle = ['userid' => $userid, 'alayacareid' => '5005', 'anniversarydate' => $anniversary];

        $legacy = $this->request('PUT', '/v1/cycles/CYC-5005-2026', ['duedate' => $anniversary] + $cycle, 'cyc-0');
        $this->assertSame(400, $legacy['status']);
        $this->assertStringContainsString('Unexpected field(s): duedate', $legacy['body']['details']);

        $created = $this->request('PUT', '/v1/cycles/CYC-5005-2026', $cycle, 'cyc-1');
        $this->assertSame(201, $created['status']);
        $this->assertSame('open', $created['body']['cycle']['status']);
        $this->assertSame($anniversary, $created['body']['cycle']['anniversarydate']);
        $this->assertArrayNotHasKey('duedate', $created['body']['cycle']);

        $fetched = $this->request('GET', '/v1/cycles/CYC-5005-2026');
        $this->assertSame(200, $fetched['status']);
        $this->assertContains('time_policy_unresolved', $fetched['body']['gates']);

        $list = $this->request('GET', '/v1/cycles', null, '', ['cycleids' => 'CYC-5005-2026, CYC-NONE']);
        $this->assertSame(1, $list['body']['count']);
        $this->assertArrayHasKey('servertime', $list['body']);

        $conflict = $this->request('PUT', '/v1/cycles/CYC-5005-2027', ['anniversarydate' => '2099-12-31'] + $cycle, 'cyc-2');
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('cycleconflict', $conflict['body']['error']);

        $unknown = $this->request('PUT', '/v1/cycles/CYC-X', ['alayacareid' => 'NOPE'] + $cycle, 'cyc-3');
        $this->assertSame(404, $unknown['status']);
        $this->assertSame('bindingmismatch', $unknown['body']['error']);

        $access = $this->request('PUT', '/v1/cycles/CYC-5005-2026/access', ['userid' => $userid, 'alayacareid' => '5005',
            'employmentstatus' => 'active', 'remindersenabled' => false], 'acc-1');
        $this->assertSame(200, $access['status']);
        $this->assertFalse($access['body']['cycle']['remindersenabled']);

        $this->assertSame(200, $this->request('POST', '/v1/cycles/CYC-5005-2026/repair')['status']);
        $this->assertSame(404, $this->request('GET', '/v1/cycles/CYC-NONE')['status']);
    }

    public function test_provision_duplicate_student(): void {
        $first = $this->put_learner();
        $this->assertSame(201, $first['status']);

        $again = $this->put_learner('prov-2', ['firstname' => 'Renamed']);
        $this->assertSame(200, $again['status']);
        $this->assertSame('updated', $again['body']['status']);
        $this->assertSame($first['body']['userid'], $again['body']['userid']);

        $samepayroll = $this->request('PUT', '/v1/learners/6006', ['email' => 'other@example.com', 'firstname' => 'Other',
            'lastname' => 'Synthetic', 'payrollnumber' => 'PR-5005', 'createifmissing' => true], 'prov-3');
        $this->assertSame(409, $samepayroll['status']);
        $this->assertSame('bindingconflict', $samepayroll['body']['error']);

        $sameuser = $this->request('PUT', '/v1/learners/6006', ['userid' => $first['body']['userid']], 'prov-4');
        $this->assertSame(409, $sameuser['status']);
        $this->assertSame('bindingconflict', $sameuser['body']['error']);
        $this->assertSame(404, $this->request('GET', '/v1/learners/6006')['status'], 'nothing was created');
    }

    public function test_start_cycle_scenarios(): void {
        global $DB;
        $userid = $this->put_learner()['body']['userid'];
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        $cycle = ['userid' => $userid, 'alayacareid' => '5005', 'anniversarydate' => $anniversary];

        $this->assertSame(201, $this->request('PUT', '/v1/cycles/C-1', $cycle, 'cyc-1')['status']);
        $inprogress = $this->request('PUT', '/v1/cycles/C-1', $cycle, 'cyc-2');
        $this->assertSame(200, $inprogress['status']);
        $this->assertSame('unchanged', $inprogress['body']['action']);
        $this->assertSame('open', $inprogress['body']['cycle']['status']);
        $this->assertEquals(1, $DB->count_records('local_cgt_cycle', ['userid' => $userid]));

        $nostudent = $this->request('PUT', '/v1/cycles/C-9', ['userid' => 999999] + $cycle, 'cyc-3');
        $this->assertSame(404, $nostudent['status']);
        $this->assertSame('bindingmismatch', $nostudent['body']['error']);

        $this->setAdminUser();
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
        $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_evidence($this->fixture, $userid);
        $this->assertTrue(local\completion_manager::evaluate((int) local\cycle_manager::get('C-1')->id));
        $this->setUser($this->adapter);

        $completed = $this->request('PUT', '/v1/cycles/C-1-DUP', $cycle, 'cyc-4');
        $this->assertSame(409, $completed['status']);
        $this->assertSame('alreadycompleted', $completed['body']['error']);
        $this->assertStringContainsString("due {$anniversary} in cycle C-1", $completed['body']['message']);
        $this->assertEquals(1, $DB->count_records('local_cgt_cycle', ['userid' => $userid]));
    }

    public function test_capability_is_required(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $response = $this->request('GET', '/v1/health');
        $this->assertSame(403, $response['status']);
        $this->assertSame('nopermissions', $response['body']['error']);
    }

    public function test_authenticate_bearer_token(): void {
        global $CFG, $DB;
        $CFG->enablewebservices = 1;

        $response = rest_api::authenticate('');
        $this->assertSame(401, $response['status']);
        $this->assertSame('Bearer', $response['headers']['WWW-Authenticate']);

        $response = rest_api::authenticate('Bearer not-a-real-token');
        $this->assertSame(401, $response['status']);
        $this->assertSame('invalidtoken', $response['body']['error']);

        $service = $DB->get_record('external_services', ['shortname' => rest_api::SERVICE], '*', MUST_EXIST);
        $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        $DB->insert_record('external_services_users', (object) ['externalserviceid' => $service->id,
            'userid' => $this->adapter->id, 'timecreated' => time()]);
        $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $this->adapter->id,
            \context_system::instance());
        $this->setUser(null);
        $this->assertNull(rest_api::authenticate('Bearer ' . $token));
        $this->assertEquals($this->adapter->id, $GLOBALS['USER']->id);
    }
}
