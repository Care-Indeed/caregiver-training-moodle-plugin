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

use local_caregivertraining\local\certificate;
use local_caregivertraining\local\completion_manager;
use local_caregivertraining\local\config;
use local_caregivertraining\local\cycle_manager;

/**
 * Certificate design checks: an incomplete or blank certificate is never issued.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(certificate::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(completion_manager::class)]
final class certificate_test extends \advanced_testcase {
    /** @var \stdClass */
    private $fixture;
    /** @var \stdClass */
    private $learner;
    /** @var \local_caregivertraining_generator */
    private $gen;
    /** @var int */
    private $templateid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_caregivertraining');
        $this->fixture = $this->gen->create_annual_course();
        $this->templateid = (int) $this->fixture->customcert->templateid;
        $this->learner = $this->gen->create_learner();
        $anniversary = (new \DateTimeImmutable('now', config::timezone()))->modify('+30 days')->format('Y-m-d');
        cycle_manager::upsert(['cycleid' => 'C-1', 'userid' => $this->learner->user->id,
            'alayacareid' => $this->learner->alayacareid, 'anniversarydate' => $anniversary]);
        set_config('timepolicy', config::POLICY_NOMINAL, 'local_caregivertraining');
        set_config('nominaldurations', json_encode(array_fill_keys($this->fixture->cms, 4500)), 'local_caregivertraining');
    }

    /**
     * Elements of the fixture certificate's first page.
     *
     * @return \stdClass[]
     */
    private function elements(): array {
        global $DB;
        return $DB->get_records_sql('SELECT e.* FROM {customcert_elements} e JOIN {customcert_pages} p ON p.id = e.pageid
            WHERE p.templateid = ? ORDER BY e.sequence', [$this->templateid]);
    }

    public function test_fixture_design_is_ready(): void {
        $design = certificate::design();
        $this->assertSame($this->templateid, $design['templateid']);
        $this->assertSame(1, $design['pages']);
        $this->assertSame(2, $design['elements']);
        $this->assertSame([], $design['problems']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $design['fingerprint']);
    }

    public function test_blank_template_blocks_completion_until_the_design_is_restored(): void {
        global $DB;
        $DB->delete_records_list('customcert_elements', 'id', array_keys($this->elements()));
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $cycle = cycle_manager::get('C-1');

        $this->assertSame(
            ['certificate_page_empty', 'certificate_missing_studentname', 'certificate_missing_code'],
            certificate::design()['problems']
        );
        $this->assertSame(['certificate_template_incomplete'], completion_manager::check_gates($cycle)['reasons']);
        $this->assertFalse(completion_manager::evaluate((int) $cycle->id));
        $cycle = cycle_manager::get('C-1');
        $this->assertSame('open', $cycle->status);
        $this->assertEmpty($cycle->certificatecode);
        $this->assertFalse($DB->record_exists('local_cgt_snapshot', ['cycleid' => $cycle->id, 'type' => 'completion']));
        $this->assertFalse($DB->record_exists('local_cgt_outbox', ['cycleid' => $cycle->id]));
        $this->assertFalse($DB->record_exists('local_cgt_notification', ['cycleid' => $cycle->id]));
        $exception = $DB->get_record('local_cgt_exception', ['type' => 'certificate_template_incomplete'], '*', MUST_EXIST);
        $this->assertStringContainsString('certificate_page_empty', $exception->details);

        $this->gen->add_certificate_elements($this->templateid, ['studentname', 'code']);
        $this->assertTrue(completion_manager::evaluate((int) $cycle->id), 'completes once the design is fixed');
        $this->assertNotEmpty(cycle_manager::get('C-1')->certificatecode);
    }

    public function test_certificate_must_show_name_and_code(): void {
        global $DB;
        foreach ($this->elements() as $element) {
            if ($element->element === 'studentname') {
                $DB->delete_records('customcert_elements', ['id' => $element->id]);
            }
        }
        $this->assertSame(['certificate_missing_studentname'], certificate::design()['problems']);
    }

    public function test_missing_background_image_blocks_issue(): void {
        global $DB;
        $page = $DB->get_field('customcert_pages', 'id', ['templateid' => $this->templateid]);
        $DB->insert_record('customcert_elements', (object) ['pageid' => $page, 'name' => 'Background', 'element' => 'bgimage',
            'data' => json_encode(['contextid' => \context_system::instance()->id, 'filearea' => 'image', 'itemid' => 0,
                'filepath' => '/', 'filename' => 'gone.png', 'width' => 0, 'height' => 0]),
            'sequence' => 9, 'timecreated' => time(), 'timemodified' => time()]);
        $this->assertSame(['certificate_image_missing'], certificate::design()['problems']);
    }

    public function test_required_design_blocks_any_other_design(): void {
        $approved = certificate::design()['fingerprint'];
        set_config('certificatefingerprint', strtoupper($approved), 'local_caregivertraining');
        $this->assertSame([], certificate::design()['problems']);

        $this->gen->add_certificate_elements($this->templateid, ['text']);
        $this->assertSame(['certificate_design_not_approved'], certificate::design()['problems']);
        $this->gen->create_evidence($this->fixture, (int) $this->learner->user->id);
        $this->assertFalse(completion_manager::evaluate((int) cycle_manager::get('C-1')->id));
        $this->assertSame('open', cycle_manager::get('C-1')->status);
    }

    public function test_rendered_certificate_is_checked_for_name_and_code(): void {
        $this->assertSame([], certificate::render_problems($this->learner->user));

        $other = $this->getDataGenerator()->create_user(['firstname' => 'Somebody', 'lastname' => 'Else']);
        $pdf = certificate::template()->generate_pdf(true, (int) $other->id, true);
        $this->assertSame([], certificate::content_problems($pdf, $other));
        $this->assertSame(
            ['certificate_name_not_rendered', 'certificate_code_not_rendered'],
            certificate::content_problems($pdf, $this->learner->user, 'NOTONTHEPAGE')
        );
    }

    public function test_fingerprint_follows_layout_and_images_but_not_wording(): void {
        global $DB;
        $this->gen->add_certificate_elements($this->templateid, ['text']);
        $copy = \mod_customcert\template::create('Copy', \context_system::instance()->id);
        certificate::template()->copy_to_template($copy);
        $original = certificate::design()['fingerprint'];
        $this->assertSame($original, certificate::design($copy)['fingerprint'], 'a copied design matches');

        $copyelements = $DB->get_records_sql('SELECT e.* FROM {customcert_elements} e JOIN {customcert_pages} p ON p.id = e.pageid
            WHERE p.templateid = ?', [$copy->get_id()]);
        foreach ($copyelements as $element) {
            if ($element->element === 'text') {
                $DB->set_field('customcert_elements', 'data', 'Reworded', ['id' => $element->id]);
            }
        }
        $this->assertSame($original, certificate::design($copy)['fingerprint'], 'wording is not part of the layout');

        $element = reset($copyelements);
        $DB->set_field('customcert_elements', 'posx', 150, ['id' => $element->id]);
        $this->assertNotSame($original, certificate::design($copy)['fingerprint'], 'moving an element changes it');
    }
}
