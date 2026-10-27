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
use local_caregivertraining\local\cycle_manager;
use local_caregivertraining\local\email;
use local_caregivertraining\local\notifier;

/**
 * Branded email rendering and email date formatting.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(email::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notifier::class)]
final class email_test extends \advanced_testcase {
    public function test_body_is_escaped_and_the_main_link_becomes_the_button(): void {
        $this->resetAfterTest();
        $url = 'https://example.com/course/view.php?id=2&x=1';
        $result = email::render([
            'subject' => 'Subject',
            'label' => 'Reminder',
            'body' => "Hello <b>Sam</b>,\n\nSee https://example.com/help.\n\nPick up where you left off: {$url}\n\nThanks",
            'cta' => ['url' => $url, 'label' => 'Continue Training'],
        ]);

        $this->assertStringContainsString('Hello &lt;b&gt;Sam&lt;/b&gt;,', $result['html']);
        $this->assertStringNotContainsString('<b>Sam</b>', $result['html']);
        $this->assertStringContainsString(
            '<a href="https://example.com/help"',
            $result['html'],
            'other links stay plain links, without the trailing full stop'
        );
        $this->assertSame(1, substr_count($result['html'], '>Continue Training</a>'), 'one primary button');
        $this->assertStringContainsString('Pick up where you left off:</p>', $result['html'], 'lead-in kept above the button');
        $this->assertStringNotContainsString('>' . s($url) . '</a>', $result['html'], 'the main link is the button, not text');
        $this->assertLessThan(
            strpos($result['html'], 'Thanks'),
            strpos($result['html'], '>Continue Training</a>'),
            'the button sits where the template mentions the link'
        );
        $this->assertStringStartsWith("Hello <b>Sam</b>,\n\n", $result['text'], 'plain text keeps the body as written');
        $this->assertStringContainsString("Pick up where you left off: {$url}", $result['text']);
        $this->assertStringNotContainsString('<a ', $result['text'], 'no layout markup in the plain-text part');
    }

    public function test_button_is_added_when_the_template_omits_the_link(): void {
        $this->resetAfterTest();
        $result = email::render([
            'subject' => 'Subject',
            'label' => 'Reminder',
            'body' => 'Hello',
            'cta' => ['url' => 'https://example.com/course', 'label' => 'Continue Training'],
        ]);
        $this->assertSame(1, substr_count($result['html'], '>Continue Training</a>'));
        $this->assertStringContainsString('Continue Training: https://example.com/course', $result['text']);
    }

    public function test_footer_is_shared_and_follows_the_settings(): void {
        $this->resetAfterTest();
        $footer = email::footer();
        $this->assertNull($footer['context']['guide'], 'no guide line until a link is set');
        $this->assertSame('tel:+16502788013', $footer['context']['hr']['tel']);
        $this->assertStringContainsString('HR Compliance: (650) 278-8013 | HRCompliance@careindeed.com', $footer['text']);
        $this->assertStringContainsString('CI-ION Systems: systemadmin@ci-ion.com', $footer['text']);

        set_config('quickstartguideurl', 'https://example.com/guide', 'local_caregivertraining');
        set_config('hrphone', '', 'local_caregivertraining');
        $html = email::render(['subject' => 'S', 'label' => 'L', 'body' => 'B'])['html'];
        $this->assertStringContainsString('<a href="https://example.com/guide"', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringContainsString('href="mailto:HRCompliance@careindeed.com"', $html);
    }

    public function test_email_dates_spell_out_the_month(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $gen->create_annual_course();
        $learner = $gen->create_learner();
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days');
        cycle_manager::upsert(['cycleid' => 'C-DATE', 'userid' => $learner->user->id, 'alayacareid' => $learner->alayacareid,
            'anniversarydate' => $anniversary->format('Y-m-d')]);

        $this->assertSame(
            'Due ' . $anniversary->format('F j, Y'),
            notifier::render(cycle_manager::get('C-DATE'), $learner->user, 'Due {duedate}')
        );
    }
}
