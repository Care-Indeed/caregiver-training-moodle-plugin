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
    public function test_body_is_escaped_and_links_are_clickable(): void {
        $this->resetAfterTest();
        $result = email::render([
            'subject' => 'Subject',
            'label' => 'Reminder',
            'heading' => 'Heading',
            'body' => "Hello <b>Sam</b>,\n\nStart at https://example.com/course/view.php?id=2&x=1.",
            'details' => [['Complete by', 'November 12, 2026']],
            'cta' => ['url' => 'https://example.com/course/view.php?id=2', 'label' => 'Start training'],
        ]);

        $this->assertStringContainsString('Hello &lt;b&gt;Sam&lt;/b&gt;,', $result['html']);
        $this->assertStringNotContainsString('<b>Sam</b>', $result['html']);
        $this->assertStringContainsString('<a href="https://example.com/course/view.php?id=2&amp;x=1"', $result['html'],
            'URL is linked without the trailing full stop');
        $this->assertStringContainsString('November 12, 2026', $result['html']);
        $this->assertStringContainsString("Complete by: November 12, 2026\n\nStart training: https://example.com/course/view.php?id=2",
            $result['text']);
        $this->assertStringStartsWith("Hello <b>Sam</b>,\n\n", $result['text'], 'plain text keeps the body as written');
        $this->assertStringNotContainsString('<a ', $result['text'], 'no layout markup in the plain-text part');
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
