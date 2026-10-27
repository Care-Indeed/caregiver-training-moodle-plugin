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

namespace local_caregivertraining\admin;

use local_caregivertraining\local\admin_setup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Certificate activity setting that follows the annual training course.
 *
 * The course is saved first on the same page, so when it changes this setting sees the new course: a certificate
 * from another course is replaced by the new course's only certificate, or cleared with a message to choose one.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_certificate extends \admin_setting_configselect {
    /**
     * Save the certificate, keeping it in the annual course.
     *
     * @param mixed $data submitted course module id
     * @return string empty on success, otherwise the error to show
     */
    public function write_setting($data) {
        $certificates = admin_setup::course_certificates();
        if ((int) $data === 0 || isset($certificates[(int) $data])) {
            return parent::write_setting($data);
        }
        if (count($certificates) === 1) {
            return parent::write_setting((string) array_key_first($certificates));
        }
        $this->config_write($this->name, 0);
        // The choices were built for the previous value; rebuild them for the new course on the next page load.
        $this->choices = null;
        return get_string($certificates ? 'certificatecmid_choose' : 'certificatecmid_none', 'local_caregivertraining');
    }

    /**
     * The select, which refreshes as soon as another course is picked on the same page.
     *
     * @param mixed $data current value
     * @param string $query search query to highlight
     * @return string HTML
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $PAGE->requires->js_call_amd('local_caregivertraining/settings', 'init', [
            'id_s_local_caregivertraining_courseid',
            $this->get_id(),
            get_string('notset', 'local_caregivertraining'),
        ]);
        return parent::output_html($data, $query);
    }
}
