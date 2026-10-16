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
 * Branded HTML and plain-text versions of the learner emails.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email {
    /** @var string CI primary 700, used for links and buttons (passes contrast with white text). */
    const LINK_COLOUR = '#B11517';

    /** @var array<string, string[]> Status label background and text colours by tone. */
    const TONES = [
        'info' => ['#FFF6DD', '#946D00'],
        'alert' => ['#FCE9E9', '#B11517'],
        'success' => ['#FFEFC3', '#4B3700'],
    ];

    /**
     * Render an email.
     *
     * @param array $args subject, preheader, label, tone, heading, body (plain text), and optionally details
     *     ([[label, value], ...]), image (['url' => ..., 'alt' => ...], linked to the CTA), cta (['url' => ..., 'label' => ...]),
     *     note (plain text) and hrcopy (banner text)
     * @return array{html: string, text: string}
     */
    public static function render(array $args): array {
        global $PAGE;
        $site = get_site();
        $sitename = format_string($site->fullname, true, ['context' => \context_system::instance()]);
        [$labelbg, $labelfg] = self::TONES[$args['tone'] ?? 'info'] ?? self::TONES['info'];
        $details = array_map(fn($row) => ['label' => $row[0], 'value' => $row[1]], $args['details'] ?? []);
        $cta = !empty($args['cta']['url'])
            ? $args['cta'] + ['fallback' => get_string('email_fallback', config::COMPONENT)]
            : null;
        $note = trim((string) ($args['note'] ?? ''));
        $footer = get_string('email_footer', config::COMPONENT, $sitename);

        $logo = $PAGE->get_renderer('core')->get_logo_url(null, 88);
        $html = $PAGE->get_renderer('core')->render_from_template('local_caregivertraining/email', [
            'subject' => $args['subject'],
            'preheader' => $args['preheader'] ?? '',
            'sitename' => $sitename,
            'logourl' => $logo ? $logo->out(false) : '',
            'hrcopy' => $args['hrcopy'] ?? '',
            'label' => $args['label'],
            'labelbg' => $labelbg,
            'labelfg' => $labelfg,
            'heading' => $args['heading'],
            'bodyhtml' => self::text_to_html($args['body']),
            'hasdetails' => !empty($details),
            'details' => $details,
            'image' => empty($args['image']['url']) ? null
                : $args['image'] + ['link' => $cta['url'] ?? $args['image']['url']],
            'cta' => $cta,
            'notehtml' => $note === '' ? '' : self::text_to_html($note),
            'footer' => $footer,
        ]);

        $text = [];
        if (!empty($args['hrcopy'])) {
            $text[] = $args['hrcopy'];
        }
        $text[] = trim($args['body']);
        if ($details) {
            $text[] = implode("\n", array_map(fn($row) => "{$row['label']}: {$row['value']}", $details));
        }
        if ($cta) {
            $text[] = "{$cta['label']}: {$cta['url']}";
        }
        if ($note !== '') {
            $text[] = $note;
        }
        $text[] = "--\n" . $footer;
        return ['html' => $html, 'text' => implode("\n\n", $text)];
    }

    /**
     * Escape plain text and turn it into paragraphs with clickable links.
     *
     * @param string $text
     * @return string
     */
    public static function text_to_html(string $text): string {
        $html = '';
        foreach (preg_split('/\R\s*\R/', trim($text)) as $paragraph) {
            $paragraph = preg_replace(
                '~https?://[^\s<]+[^\s<.,;:!?)\'"]~',
                '<a href="$0" target="_blank" style="color:' . self::LINK_COLOUR . ';word-break:break-all;">$0</a>',
                s($paragraph)
            );
            $html .= '<p style="margin:0 0 14px 0;">' . nl2br($paragraph, false) . '</p>';
        }
        return $html;
    }

    /**
     * Format a compliance-timezone timestamp as an unambiguous date, e.g. "November 12, 2026".
     *
     * @param int $timestamp
     * @return string
     */
    public static function date(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedue', config::COMPONENT), config::timezone()->getName());
    }
}
