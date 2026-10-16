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

/**
 * Show the configured certificate's design, or replace it with a copy of another template.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_caregivertraining\local\certificate;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'from' => 0,
    'execute' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$help = <<<EOT
Show the design of the configured certificate (the certificatecmid setting), or replace it with a
copy of another Custom certificate template, such as the Care Indeed annual training design.

Without --from, prints the configured design and anything that blocks issuing it.
With --from, compares the two designs; add --execute to replace the configured design. Certificates
already issued keep their codes and show the new design the next time they are rendered.

Options:
  --from=ID     Template to copy (customcert_templates.id)
  --execute     Make the change (default: dry run)
  -h, --help    Print this help

Example:
  php public/local/caregivertraining/cli/apply_certificate_design.php --from=36 --execute

EOT;

if ($options['help']) {
    echo $help;
    exit(0);
}

\core\session\manager::set_user(get_admin());

$print = function (string $label, array $design): void {
    cli_writeln("{$label}: template {$design['templateid']} \"{$design['name']}\", {$design['pages']} pages, "
        . "{$design['elements']} elements");
    cli_writeln("  fingerprint {$design['fingerprint']}");
    cli_writeln('  ' . ($design['problems'] ? 'cannot be issued: ' . implode(', ', $design['problems']) : 'ready to issue'));
};

$target = certificate::template();
if (!$target) {
    cli_error('No certificate is configured. Set certificatecmid to the Custom certificate in the annual course first.');
}
$print('Configured', certificate::design($target));

if (empty($options['from'])) {
    exit(certificate::design($target)['problems'] ? 1 : 0);
}

$record = $DB->get_record('customcert_templates', ['id' => (int) $options['from']]);
if (!$record) {
    cli_error("Template {$options['from']} does not exist.");
}
$source = new \mod_customcert\template($record);
$sourcedesign = certificate::design($source);
$print('Source', $sourcedesign);

if ((int) $source->get_id() === (int) $target->get_id()) {
    cli_error('The source is the configured template itself.');
}
if ($sourcedesign['problems']) {
    cli_error('Refusing to copy a design that cannot be issued.');
}
if (!$options['execute']) {
    cli_writeln($sourcedesign['fingerprint'] === certificate::design($target)['fingerprint']
        ? 'The layouts already match. Run with --execute to copy the wording as well.'
        : 'Dry run. Run with --execute to replace the configured design.');
    exit(0);
}

$transaction = $DB->start_delegated_transaction();
foreach ($DB->get_records('customcert_pages', ['templateid' => $target->get_id()]) as $page) {
    $target->delete_page($page->id, false);
}
$source->copy_to_template($target);
$transaction->allow_commit();

$result = certificate::design($target);
$print('Now', $result);
exit($result['problems'] ? 1 : 0);
