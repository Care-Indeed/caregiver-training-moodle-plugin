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
 * Redirect to the next available activity (after cmid, if given). The target page still enforces its own restrictions.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_caregivertraining\local\next_activity;

$courseid = required_param('courseid', PARAM_INT);
$aftercmid = optional_param('cmid', 0, PARAM_INT);
$course = get_course($courseid);
require_login($course);

$cm = next_activity::find($course, (int) $USER->id, $aftercmid);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
if (!$cm) {
    redirect($courseurl, get_string('nonextactivity', 'local_caregivertraining'), null, \core\output\notification::NOTIFY_INFO);
}
redirect($cm->url ?? $courseurl);
