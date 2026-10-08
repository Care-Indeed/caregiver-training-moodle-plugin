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

use local_caregivertraining\local\binding_manager;
use local_caregivertraining\local\profile_fields;

/**
 * Identity binding: duplicates, conflicts, no email auto-linking, protected fields, activation.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(binding_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(profile_fields::class)]
final class binding_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Base provisioning payload.
     *
     * @param array $overrides
     * @return array
     */
    private function payload(array $overrides = []): array {
        return $overrides + ['alayacareid' => '1001', 'payrollnumber' => 'PR-1001',
            'email' => 'casey@example.com', 'firstname' => 'Casey', 'lastname' => 'Synthetic', 'hcanumber' => 'HCA-1',
            'registrationdate' => '2025-06-30', 'createifmissing' => 1, 'sendactivation' => 0];
    }

    /**
     * Assert a moodle_exception with an error code.
     *
     * @param string $code
     * @param callable $fn
     * @return \moodle_exception
     */
    private function assert_error(string $code, callable $fn): \moodle_exception {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode, $e->getMessage());
            return $e;
        }
        $this->fail("Expected {$code}");
    }

    public function test_create_then_repeat_is_unchanged_and_fields_are_protected(): void {
        global $DB;
        $created = binding_manager::provision($this->payload());
        $this->assertSame('created', $created['status']);
        $repeat = binding_manager::provision($this->payload());
        $this->assertSame('unchanged', $repeat['status']);
        $this->assertSame($created['userid'], $repeat['userid']);
        $this->assertEquals(1, $DB->count_records('local_cgt_binding'));

        $user = $DB->get_record('user', ['id' => $created['userid']]);
        $this->assertSame('manual', $user->auth);
        $this->assertSame('casey@example.com', $user->username);

        $values = profile_fields::load((int) $created['userid']);
        $this->assertSame('1001', $values[profile_fields::ALAYACAREID]);
        $this->assertSame('PR-1001', $values[profile_fields::PAYROLLNUMBER]);
        $this->assertSame('HCA-1', $values[profile_fields::HCANUMBER]);
        $this->assertSame('2025-06-30', $values[profile_fields::REGISTRATIONDATE]);
        foreach (array_keys(profile_fields::definitions()) as $shortname) {
            $this->assertEquals(
                1,
                $DB->get_field('user_info_field', 'locked', ['shortname' => $shortname]),
                "{$shortname} must be locked against learner edits"
            );
        }
        $this->assertEquals(1, $DB->get_field('user_info_field', 'forceunique', ['shortname' => profile_fields::ALAYACAREID]));
        $this->assertEquals(1, $DB->get_field('user_info_field', 'forceunique', ['shortname' => profile_fields::PAYROLLNUMBER]));
    }

    public function test_locked_fields_are_not_editable_by_the_learner(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $created = binding_manager::provision($this->payload());
        $this->setUser($created['userid']);
        $this->assertFalse(has_capability('moodle/user:update', \context_system::instance()));
        $seen = 0;
        foreach (profile_get_user_fields_with_data((int) $created['userid']) as $field) {
            if (array_key_exists($field->get_shortname(), profile_fields::definitions())) {
                // Locked fields are hard-frozen to their stored value for users without moodle/user:update.
                $this->assertTrue($field->is_locked(), $field->get_shortname());
                $form = new \MoodleQuickForm('test', 'post', '');
                $field->edit_field($form);
                $field->edit_field_set_locked($form);
                $this->assertTrue($form->getElement($field->inputname)->isFrozen(), $field->get_shortname());
                $seen++;
            }
        }
        $this->assertSame(4, $seen);
    }

    public function test_duplicate_payroll_number_is_rejected(): void {
        global $DB;
        binding_manager::provision($this->payload());
        $this->assert_error('error:bindingconflict', fn() => binding_manager::provision($this->payload([
            'alayacareid' => '2002', 'email' => 'other@example.com'])));
        $this->assertTrue($DB->record_exists('local_cgt_exception', ['type' => 'duplicate_payrollnumber']));
        $this->assertEquals(1, $DB->count_records('local_cgt_binding'));
    }

    public function test_existing_email_is_never_auto_linked(): void {
        global $DB;
        $existing = $this->getDataGenerator()->create_user(['email' => 'casey@example.com']);
        $e = $this->assert_error('error:existingaccount', fn() => binding_manager::provision($this->payload()));
        $this->assertEquals($existing->id, $e->a);
        $this->assertFalse($DB->record_exists('local_cgt_binding', []));

        $linked = binding_manager::provision($this->payload(['userid' => $existing->id]));
        $this->assertSame('linked', $linked['status']);
        $this->assertEquals($existing->id, $linked['userid']);
    }

    public function test_ambiguous_email_creates_nothing(): void {
        global $CFG, $DB;
        $CFG->allowaccountssameemail = 1;
        $this->getDataGenerator()->create_user(['email' => 'casey@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'CASEY@example.com']);
        $usersbefore = $DB->count_records('user');
        $this->assert_error('error:ambiguousemail', fn() => binding_manager::provision($this->payload()));
        $this->assertEquals($usersbefore, $DB->count_records('user'));
        $this->assertTrue($DB->record_exists('local_cgt_exception', ['type' => 'ambiguous_email']));
        $this->assertSame('ambiguous', binding_manager::lookup(['email' => 'casey@example.com'])['status']);
    }

    public function test_binding_is_immutable_and_lookup_reports_conflicts(): void {
        $first = binding_manager::provision($this->payload());
        $other = $this->getDataGenerator()->create_user();
        $this->assert_error('error:bindingconflict', fn() => binding_manager::provision($this->payload(['userid' => $other->id])));
        $this->assert_error('error:bindingconflict', fn() => binding_manager::provision($this->payload([
            'alayacareid' => '3003', 'payrollnumber' => 'PR-3003', 'userid' => $first['userid']])));

        binding_manager::provision($this->payload(['alayacareid' => '4004', 'payrollnumber' => 'PR-4004',
            'email' => 'dana@example.com']));
        $lookup = binding_manager::lookup(['alayacareid' => '1001']);
        $this->assertSame('bound', $lookup['status']);
        $this->assertSame('HCA-1', $lookup['bindings'][0]['hcanumber']);
        $this->assertSame('2025-06-30', $lookup['bindings'][0]['registrationdate']);
        $this->assertSame('conflict', binding_manager::lookup(['alayacareid' => '1001', 'payrollnumber' => 'PR-4004'])['status']);
        $this->assertSame('none', binding_manager::lookup(['alayacareid' => '9999'])['status']);
    }

    public function test_user_fields_query_reads_employment_profile(): void {
        global $DB;
        $created = binding_manager::provision($this->payload());
        $fields = profile_fields::user_fields()->get_sql('u', true);
        $record = $DB->get_record_sql(
            "SELECT u.id {$fields->selects} FROM {user} u {$fields->joins} WHERE u.id = :userid",
            $fields->params + ['userid' => $created['userid']]
        );
        $values = profile_fields::values_from_record($record);
        $this->assertSame('1001', $values['alayacareid']);
        $this->assertSame('PR-1001', $values['payrollnumber']);
        $this->assertSame('HCA-1', $values['hcanumber']);
        $this->assertSame('2025-06-30', $values['registrationdate']);
        $profile = profile_fields::load((int) $created['userid']);
        $this->assertSame($values['registrationdate'], $profile[profile_fields::REGISTRATIONDATE]);
    }

    public function test_not_found_without_create_flag(): void {
        global $DB;
        $result = binding_manager::provision($this->payload(['createifmissing' => 0]));
        $this->assertSame('notfound', $result['status']);
        $this->assertFalse($DB->record_exists('user', ['email' => 'casey@example.com']));
    }

    public function test_activation_uses_time_limited_reset_token(): void {
        global $DB;
        $result = binding_manager::provision($this->payload(['sendactivation' => 1]));
        $this->assertSame('queued', $result['activation']);
        $sink = $this->redirectEmails();
        $this->expectOutputRegex('/Activation email sent/');
        $this->runAdhocTasks(\local_caregivertraining\task\send_activation::class);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('casey@example.com', $messages[0]->to);
        $this->assertStringContainsString('/login/forgot_password.php?token=', quoted_printable_decode($messages[0]->body));
        $this->assertTrue($DB->record_exists('user_password_resets', ['userid' => $result['userid']]));

        $DB->set_field('user', 'lastlogin', time(), ['id' => $result['userid']]);
        $this->assertSame('alreadyactive', binding_manager::provision($this->payload(['sendactivation' => 1]))['activation']);
    }
}
