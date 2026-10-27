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
 * Refreshes the certificate activity list as soon as another annual training course is picked, before saving.
 *
 * @module     local_caregivertraining/settings
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Keep the certificate list in step with the selected course.
 *
 * @param {string} courseid id of the course select
 * @param {string} certificateid id of the certificate select
 * @param {string} notset label of the empty option
 */
export const init = (courseid, certificateid, notset) => {
    const course = document.getElementById(courseid);
    const certificate = document.getElementById(certificateid);
    if (!course || !certificate) {
        return;
    }
    let request = 0;
    // The course autocomplete updates its underlying select and fires change on it.
    course.addEventListener('change', () => {
        const current = request += 1;
        Ajax.call([{
            methodname: 'local_caregivertraining_course_certificates',
            args: {courseid: parseInt(course.value, 10) || 0},
        }])[0].then((certificates) => {
            // Skip a response when a later change is already loading.
            if (current === request) {
                const selected = certificate.value;
                certificate.replaceChildren(new Option(notset, '0'));
                certificates.forEach(({cmid, name}) => certificate.add(new Option(name, String(cmid))));
                // Keep the choice when it is in the new course; pick the course's only certificate; otherwise ask.
                let value = '0';
                if (certificates.some(({cmid}) => String(cmid) === selected)) {
                    value = selected;
                } else if (certificates.length === 1) {
                    value = String(certificates[0].cmid);
                }
                certificate.value = value;
                certificate.dispatchEvent(new Event('change', {bubbles: true}));
            }
            return certificates;
        }).catch(Notification.exception);
    });
};
