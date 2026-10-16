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
 * The certificate earned in a cycle: the PDF kept with the completion snapshot, a PNG of its first page,
 * and their public copies in S3.
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

    /** @var callable|null AWS handler that replaces the network, for tests. */
    public static $handler = null;

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
     * The certificate PDF stored with the cycle's newest verified completion snapshot, if there is one.
     *
     * @param \stdClass $cycle
     * @return \stored_file|null
     */
    public static function pdf_file(\stdClass $cycle): ?\stored_file {
        global $DB;
        if (empty($cycle->certificatecode)) {
            return null;
        }
        $snapshots = $DB->get_records(
            'local_cgt_snapshot',
            ['cycleid' => $cycle->id, 'type' => 'completion', 'verified' => 1],
            'id DESC',
            'id',
            0,
            1
        );
        if (!$snapshot = reset($snapshots)) {
            return null;
        }
        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            config::COMPONENT,
            'snapshotcert',
            $snapshot->id,
            '/',
            self::filename($cycle, 'pdf')
        );
        return $file ?: null;
    }

    /**
     * A PNG of the certificate's first page, rendered once and kept next to the PDF so that the
     * snapshot's retention and privacy deletion cover it.
     *
     * @param \stdClass $cycle
     * @return \stored_file|null null when there is no PDF or it cannot be rendered
     */
    public static function png_file(\stdClass $cycle): ?\stored_file {
        if (!$pdf = self::pdf_file($cycle)) {
            return null;
        }
        $fs = get_file_storage();
        $record = [
            'contextid' => $pdf->get_contextid(),
            'component' => $pdf->get_component(),
            'filearea' => $pdf->get_filearea(),
            'itemid' => $pdf->get_itemid(),
            'filepath' => '/',
            'filename' => self::filename($cycle, 'png'),
        ];
        if ($existing = $fs->get_file(...array_values($record))) {
            return $existing;
        }
        $png = self::render_png($pdf);
        return $png === null ? null : $fs->create_file_from_string($record + ['userid' => $pdf->get_userid()], $png);
    }

    /**
     * Whether certificates are published to S3.
     *
     * @return bool
     */
    public static function publishing_enabled(): bool {
        // Unit tests must never reach real storage, whatever the container environment says.
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && !self::$handler) {
            return false;
        }
        return config::s3_bucket() !== '' && config::s3_public_url() !== '';
    }

    /**
     * Public address of a published certificate file.
     *
     * @param \stdClass $cycle
     * @param string $extension pdf or png
     * @return string
     */
    public static function public_url(\stdClass $cycle, string $extension): string {
        return config::s3_public_url() . '/' . implode('/', array_map('rawurlencode', explode('/', self::key($cycle, $extension))));
    }

    /**
     * Upload the certificate PDF and PNG to S3. Uploading again replaces the objects, so this is safe to repeat.
     *
     * @param \stdClass $cycle
     * @return array{pdf: string, png?: string}|null public URLs, or null when publishing is off or there is no certificate
     * @throws \Throwable when an upload fails
     */
    public static function publish(\stdClass $cycle): ?array {
        if (!self::publishing_enabled() || !$pdf = self::pdf_file($cycle)) {
            return null;
        }
        $files = ['pdf' => [$pdf, 'application/pdf']];
        if ($png = self::png_file($cycle)) {
            $files['png'] = [$png, 'image/png'];
        }
        $client = self::client();
        $urls = [];
        foreach ($files as $extension => [$file, $type]) {
            $client->putObject([
                'Bucket' => config::s3_bucket(),
                'Key' => self::key($cycle, $extension),
                'Body' => $file->get_content(),
                'ContentType' => $type,
                'ContentDisposition' => 'inline; filename="certificate-' . self::filename($cycle, $extension) . '"',
                // Short, because a corrected snapshot replaces the object under the same key.
                'CacheControl' => 'public, max-age=300',
            ]);
            $urls[$extension] = self::public_url($cycle, $extension);
        }
        return $urls;
    }

    /**
     * Delete the public copies.
     *
     * @param \stdClass $cycle
     * @throws \Throwable when a delete fails
     */
    public static function unpublish(\stdClass $cycle): void {
        if (!self::publishing_enabled() || empty($cycle->certificatecode)) {
            return;
        }
        $client = self::client();
        foreach (['pdf', 'png'] as $extension) {
            $client->deleteObject(['Bucket' => config::s3_bucket(), 'Key' => self::key($cycle, $extension)]);
        }
    }

    /**
     * File name for a certificate file.
     *
     * @param \stdClass $cycle
     * @param string $extension
     * @return string
     */
    private static function filename(\stdClass $cycle, string $extension): string {
        return clean_param($cycle->certificatecode, PARAM_ALPHANUMEXT) . '.' . $extension;
    }

    /**
     * S3 key for a certificate file. The certificate code is random, so keys cannot be guessed.
     *
     * @param \stdClass $cycle
     * @param string $extension
     * @return string
     */
    private static function key(\stdClass $cycle, string $extension): string {
        return config::s3_prefix() . self::filename($cycle, $extension);
    }

    /**
     * Render the first page of a PDF to PNG with Ghostscript.
     *
     * @param \stored_file $pdf
     * @return string|null PNG bytes, or null when Ghostscript is unavailable or fails
     */
    private static function render_png(\stored_file $pdf): ?string {
        if (!$gs = self::ghostscript()) {
            return null;
        }
        $dir = make_request_directory();
        $input = "{$dir}/certificate.pdf";
        $output = "{$dir}/certificate.png";
        $pdf->copy_content_to($input);
        $command = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r150'
            . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -dFirstPage=1 -dLastPage=1'
            . ' -sOutputFile=' . escapeshellarg($output) . ' ' . escapeshellarg($input) . ' 2>&1';
        exec($command, $lines, $status);
        if ($status !== 0 || !is_readable($output) || !filesize($output)) {
            debugging('Certificate PNG rendering failed: ' . implode(' ', $lines), DEBUG_DEVELOPER);
            return null;
        }
        return file_get_contents($output);
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

    /**
     * S3 client for the configured bucket.
     *
     * @return \Aws\S3\S3Client
     */
    private static function client(): \Aws\S3\S3Client {
        $options = [
            'version' => 'latest',
            'region' => config::s3_region(),
            'http' => ['connect_timeout' => 10, 'timeout' => 60],
        ];
        if ($endpoint = config::s3_endpoint()) {
            $options['endpoint'] = $endpoint;
            $options['use_path_style_endpoint'] = true;
        }
        if (config::s3_access_key() !== '') {
            $options['credentials'] = ['key' => config::s3_access_key(), 'secret' => config::s3_secret_key()];
        }
        if (self::$handler) {
            $options['handler'] = self::$handler;
        }
        return new \Aws\S3\S3Client($options);
    }
}
