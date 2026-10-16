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

namespace local_caregivertraining\local;

/**
 * Checks that the configured certificate can be issued: its design is complete and it renders the
 * learner's name and certificate code.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate {
    /** @var string[] Elements that identify the learner and make the certificate verifiable. */
    const REQUIRED_ELEMENTS = ['studentname', 'code'];

    /** @var string[] Elements that draw an uploaded image. */
    const IMAGE_ELEMENTS = ['bgimage', 'image'];

    /**
     * Template of the configured certificate activity.
     *
     * @return \mod_customcert\template|null
     */
    public static function template(): ?\mod_customcert\template {
        global $DB;
        $cmid = config::certificate_cmid();
        $cm = $cmid ? get_coursemodule_from_id('customcert', $cmid) : false;
        $templateid = $cm ? $DB->get_field('customcert', 'templateid', ['id' => $cm->instance]) : false;
        $record = $templateid ? $DB->get_record('customcert_templates', ['id' => $templateid]) : false;
        return $record ? new \mod_customcert\template($record) : null;
    }

    /**
     * Describe a certificate design and list what stops it from being issued.
     *
     * The fingerprint covers page sizes, element types, positions, fonts, colours and the content of every image,
     * but not text wording, so the sanitized local copy (which replaces staff names) matches production.
     *
     * @param \mod_customcert\template|null $template defaults to the configured certificate
     * @return array{templateid: int, name: string, pages: int, elements: int, fingerprint: string, problems: string[]}
     */
    public static function design(?\mod_customcert\template $template = null): array {
        global $DB;
        $template = $template ?? self::template();
        if (!$template) {
            return ['templateid' => 0, 'name' => '', 'pages' => 0, 'elements' => 0, 'fingerprint' => '',
                'problems' => ['certificate_not_configured']];
        }
        $pages = $DB->get_records('customcert_pages', ['templateid' => $template->get_id()], 'sequence, id');
        $problems = $pages ? [] : ['certificate_template_empty'];
        $types = [];
        $layout = [];
        $count = 0;
        foreach ($pages as $page) {
            $elements = $DB->get_records('customcert_elements', ['pageid' => $page->id], 'sequence, id');
            if (!$elements) {
                $problems[] = 'certificate_page_empty';
            }
            $items = [];
            foreach ($elements as $element) {
                $count++;
                $types[$element->element] = true;
                $content = $element->element === 'text' ? '' : (string) $element->data;
                if (in_array($element->element, self::IMAGE_ELEMENTS, true)) {
                    $file = self::element_file($element);
                    if (!$file) {
                        $problems[] = 'certificate_image_missing';
                    }
                    $content = $file ? $file->get_contenthash() : 'missing';
                }
                $items[] = [$element->element, $content, $element->font, (int) $element->fontsize, $element->colour,
                    (int) $element->posx, (int) $element->posy, (int) $element->width, (int) $element->refpoint,
                    (string) $element->alignment];
            }
            $layout[] = [(int) $page->width, (int) $page->height, (int) $page->leftmargin, (int) $page->rightmargin, $items];
        }
        foreach (self::REQUIRED_ELEMENTS as $required) {
            if ($pages && empty($types[$required])) {
                $problems[] = "certificate_missing_{$required}";
            }
        }
        $fingerprint = hash('sha256', json_encode($layout));
        $required = config::certificate_fingerprint();
        if ($required !== '' && $fingerprint !== $required) {
            $problems[] = 'certificate_design_not_approved';
        }
        return [
            'templateid' => (int) $template->get_id(),
            'name' => (string) $template->get_name(),
            'pages' => count($pages),
            'elements' => $count,
            'fingerprint' => $fingerprint,
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * Render the configured certificate for a learner, without issuing it, and check that it shows their name.
     *
     * @param \stdClass $user
     * @return string[] problem codes; empty when the certificate renders correctly
     */
    public static function render_problems(\stdClass $user): array {
        if (!$template = self::template()) {
            return ['certificate_not_configured'];
        }
        try {
            $pdf = $template->generate_pdf(true, (int) $user->id, true);
        } catch (\Throwable $e) {
            debugging('Certificate preview failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['certificate_render_failed'];
        }
        if (!is_string($pdf) || $pdf === '') {
            return ['certificate_render_failed'];
        }
        return self::content_problems($pdf, $user);
    }

    /**
     * Check that a rendered certificate shows the learner's name and, when given, the certificate code.
     *
     * @param string $pdf
     * @param \stdClass $user
     * @param string|null $code
     * @return string[] problem codes; empty when the text is present or cannot be read
     */
    public static function content_problems(string $pdf, \stdClass $user, ?string $code = null): array {
        if (($text = self::pdf_text($pdf)) === null) {
            // Without Ghostscript the structural checks in design() still apply.
            debugging('Certificate text could not be read; Ghostscript is unavailable or failed.', DEBUG_DEVELOPER);
            return [];
        }
        $problems = [];
        if (!str_contains($text, self::normalise(fullname($user)))) {
            $problems[] = 'certificate_name_not_rendered';
        }
        if ($code !== null && !str_contains($text, self::normalise($code))) {
            $problems[] = 'certificate_code_not_rendered';
        }
        return $problems;
    }










    /**
     * Text of a PDF, lower case with whitespace collapsed.
     *
     * @param string $pdf
     * @return string|null null when Ghostscript is unavailable or fails
     */
    private static function pdf_text(string $pdf): ?string {
        if (!$gs = self::ghostscript()) {
            return null;
        }
        $input = make_request_directory() . '/certificate.pdf';
        file_put_contents($input, $pdf);
        $command = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- '
            . escapeshellarg($input) . ' 2>/dev/null';
        exec($command, $lines, $status);
        return $status === 0 ? self::normalise(implode(' ', $lines)) : null;
    }

    /**
     * Lower case with whitespace collapsed, for comparing rendered text.
     *
     * @param string $text
     * @return string
     */
    private static function normalise(string $text): string {
        return \core_text::strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Ghostscript executable, if installed.
     *
     * @return string|null
     */
    private static function ghostscript(): ?string {
        global $CFG;
        $gs = !empty($CFG->pathtogs) ? $CFG->pathtogs : '/usr/bin/gs';
        return is_executable($gs) ? $gs : null;
    }

    /**
     * The uploaded file an image element draws.
     *
     * @param \stdClass $element customcert_elements record
     * @return \stored_file|null
     */
    private static function element_file(\stdClass $element): ?\stored_file {
        $data = json_decode((string) $element->data, true);
        if (empty($data['filename']) || empty($data['contextid'])) {
            return null;
        }
        $file = get_file_storage()->get_file(
            (int) $data['contextid'],
            'mod_customcert',
            (string) ($data['filearea'] ?? 'image'),
            (int) ($data['itemid'] ?? 0),
            (string) ($data['filepath'] ?? '/'),
            (string) $data['filename']
        );
        return $file ?: null;
    }

}
