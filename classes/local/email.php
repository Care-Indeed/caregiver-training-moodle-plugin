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
     * The body is plain text as written in the template. The primary button replaces the main link where the body
     * mentions it (the text before the link stays as its lead-in); other links stay plain text links.
     *
     * @param array $args subject, preheader, label, tone, body (plain text), and optionally cta (['url' => ...,
     *     'label' => ...]), image (['url' => ..., 'alt' => ...], linked to the CTA) and hrcopy (banner text)
     * @return array{html: string, text: string}
     */
    public static function render(array $args): array {
        global $PAGE;
        $site = get_site();
        $sitename = format_string($site->fullname, true, ['context' => \context_system::instance()]);
        [$labelbg, $labelfg] = self::TONES[$args['tone'] ?? 'info'] ?? self::TONES['info'];
        $cta = !empty($args['cta']['url']) ? $args['cta'] : null;
        $body = trim($args['body']);

        // Split the body around the main link, so the button sits where the template mentions it.
        $blocks = [];
        $placed = false;
        foreach (preg_split('/\R\s*\R/', $body) as $paragraph) {
            if ($cta && !$placed && str_ends_with(rtrim($paragraph), $cta['url'])) {
                $leadin = trim(substr(rtrim($paragraph), 0, -strlen($cta['url'])));
                if ($leadin !== '') {
                    $blocks[] = ['html' => self::text_to_html($leadin)];
                }
                $blocks[] = ['button' => $cta];
                $placed = true;
            } else {
                $blocks[] = ['html' => self::text_to_html($paragraph)];
            }
        }
        if ($cta && !$placed) {
            $blocks[] = ['button' => $cta];
        }
        $footer = self::footer();

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
            'heading' => $args['subject'],
            'blocks' => $blocks,
            'image' => empty($args['image']['url']) ? null
                : $args['image'] + ['link' => $cta['url'] ?? $args['image']['url']],
            'footer' => $footer['context'],
        ]);

        $text = [];
        if (!empty($args['hrcopy'])) {
            $text[] = $args['hrcopy'];
        }
        $text[] = $body;
        if ($cta && !$placed) {
            $text[] = "{$cta['label']}: {$cta['url']}";
        }
        $text[] = "--\n" . $footer['text'];
        return ['html' => $html, 'text' => implode("\n\n", $text)];
    }

    /**
     * The footer shared by every email: help guide, HR Compliance and platform support contacts.
     *
     * Built in this one place (and the email_footer template) so a contact change is made once. Lines whose
     * setting is empty are left out.
     *
     * @return array{context: array, text: string} template context and plain-text version
     */
    public static function footer(): array {
        $component = config::COMPONENT;
        $guide = config::quick_start_guide_url();
        $phone = config::hr_phone();
        $hremail = config::hr_email();
        $support = config::support_email();
        $digits = preg_replace('/\D/', '', $phone);
        $context = [
            'guide' => $guide === '' ? null : ['heading' => get_string('email_footer_help', $component),
                'text' => get_string('email_footer_guide', $component), 'url' => $guide],
            'hr' => $phone === '' && $hremail === '' ? null : [
                'heading' => get_string('email_footer_training', $component),
                'label' => get_string('email_footer_hr', $component),
                'phone' => $phone,
                'tel' => $phone === '' ? '' : 'tel:+' . (strlen($digits) === 10 ? '1' . $digits : $digits),
                'separator' => $phone !== '' && $hremail !== '',
                'email' => $hremail,
            ],
            'support' => $support === '' ? null : ['heading' => get_string('email_footer_platform', $component),
                'label' => get_string('email_footer_systems', $component), 'email' => $support],
        ];

        $lines = [];
        if ($context['guide']) {
            $lines[] = "{$context['guide']['heading']}\n{$context['guide']['text']} {$guide}";
        }
        if ($context['hr']) {
            $lines[] = "{$context['hr']['heading']}\n{$context['hr']['label']} "
                . implode(' | ', array_filter([$phone, $hremail]));
        }
        if ($context['support']) {
            $lines[] = "{$context['support']['heading']}\n{$context['support']['label']} {$support}";
        }
        return ['context' => $context, 'text' => implode("\n\n", $lines)];
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
