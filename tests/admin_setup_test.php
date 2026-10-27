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

use local_caregivertraining\local\admin_setup;
use local_caregivertraining\local\config;

/**
 * Settings pages: choice lists, the setup checklist and the saved-value formats they keep.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(admin_setup::class)]
final class admin_setup_test extends \advanced_testcase {
    public function test_setup_warnings_list_only_what_blocks_completion(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php');
        $html = admin_setup::setup_warnings();
        foreach (['setup_nocourse', 'setup_nocertificate', 'setup_nopolicy'] as $key) {
            $this->assertStringContainsString(s(get_string($key, config::COMPONENT)), $html);
        }
        $this->assertStringContainsString('section=local_caregivertraining_time', $html);

        $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_annual_course();
        set_config('timepolicy', config::POLICY_TRACKED, 'local_caregivertraining');
        $this->assertStringContainsString(s(get_string('setup_noactivities', config::COMPONENT)), admin_setup::setup_warnings());
        set_config('countallactivities', 1, 'local_caregivertraining');
        $this->assertSame('', admin_setup::setup_warnings());
    }

    public function test_choices_follow_the_annual_course(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php');
        $this->assertSame([], admin_setup::activity_choices());
        $this->assertSame([0], array_keys(admin_setup::certificate_choices()));

        $fixture = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_annual_course();
        $this->assertArrayHasKey($fixture->course->id, admin_setup::course_choices());
        $this->assertArrayNotHasKey(SITEID, admin_setup::course_choices());
        $this->assertSame([0, (int) $fixture->customcert->cmid], array_keys(admin_setup::certificate_choices()));
        $activities = admin_setup::activity_choices();
        $this->assertArrayHasKey($fixture->lesson->cmid, $activities);
        $this->assertStringContainsString(get_string('modulename', 'lesson'), $activities[$fixture->lesson->cmid]);
        $this->assertStringContainsString('<code>' . $fixture->quiz->cmid . '</code>', admin_setup::activity_id_list());

        // Saved ids that left the course stay selectable, so saving the page does not silently drop them.
        set_config('countablecmids', $fixture->lesson->cmid . ',999999', 'local_caregivertraining');
        $this->assertArrayHasKey(999999, admin_setup::activity_choices());
        set_config('certificatecmid', 999998, 'local_caregivertraining');
        $this->assertArrayHasKey(999998, admin_setup::certificate_choices());
    }

    public function test_changing_the_course_moves_the_certificate_to_the_new_course(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $old = $gen->create_annual_course();
        $new = $gen->create_annual_course();
        $prefix = 's_local_caregivertraining_';
        $switch = function () use ($old, $new, $prefix) {
            set_config('courseid', $old->course->id, 'local_caregivertraining');
            set_config('certificatecmid', $old->customcert->cmid, 'local_caregivertraining');
            admin_get_root(true, true);
            // The form still holds the old course's certificate, as it does right after picking a new course.
            admin_write_settings((object) [
                $prefix . 'courseid' => $new->course->id,
                $prefix . 'certificatecmid' => $old->customcert->cmid,
            ]);
            return admin_get_root()->errors;
        };

        $this->assertSame([], $switch());
        $this->assertSame((int) $new->course->id, config::course_id());
        $this->assertSame((int) $new->customcert->cmid, config::certificate_cmid(), 'its only certificate is chosen');

        $this->getDataGenerator()->create_module('customcert', ['course' => $new->course->id]);
        $errors = $switch();
        $this->assertSame(0, config::certificate_cmid(), 'with two certificates the admin chooses');
        $this->assertSame(
            get_string('certificatecmid_choose', config::COMPONENT),
            $errors['s_local_caregivertraining_certificatecmid']->error
        );
    }

    public function test_certificate_list_service_follows_the_requested_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $saved = $gen->create_annual_course();
        $other = $this->getDataGenerator()->create_course();
        $certificate = $this->getDataGenerator()->create_module('customcert', ['course' => $other->id, 'name' => 'Other cert']);

        $result = \core_external\external_api::clean_returnvalue(
            \local_caregivertraining\external\course_certificates::execute_returns(),
            \local_caregivertraining\external\course_certificates::execute((int) $other->id)
        );
        $this->assertSame([['cmid' => (int) $certificate->cmid, 'name' => 'Other cert']], $result);
        $this->assertSame([], \local_caregivertraining\external\course_certificates::execute(0));
        $this->assertSame((int) $saved->course->id, config::course_id(), 'looking does not change the saved course');

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        \local_caregivertraining\external\course_certificates::execute((int) $other->id);
    }

    public function test_settings_save_in_the_formats_config_reads(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining')->create_annual_course();
        $root = admin_get_root(true, true);
        foreach (admin_setup::PAGES as $name) {
            $this->assertNotNull($root->locate($name), $name);
            $this->assertNotEmpty($root->locate($name)->output_html());
        }
        $prefix = 's_local_caregivertraining_';
        admin_write_settings((object) [
            $prefix . 'requiredseconds' => ['v' => 4, 'u' => HOURSECS],
            $prefix . 'countablecmids' => [$fixture->lesson->cmid, $fixture->quiz->cmid],
            $prefix . 'idleseconds' => ['v' => 3, 'u' => MINSECS],
        ]);
        $this->assertSame(4 * HOURSECS, config::required_seconds());
        $this->assertEqualsCanonicalizing(
            [(int) $fixture->lesson->cmid, (int) $fixture->quiz->cmid],
            config::countable_cmids()
        );
        $this->assertSame(180, config::idle_seconds());
    }
}
