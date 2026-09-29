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
 * Moves the training banner into the main content region so it follows the theme's page layout.
 *
 * The banner can only be output at the top of the body; styles.css keeps it hidden there while JS is enabled.
 *
 * @module     local_caregivertraining/banner
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    const banner = document.querySelector('#page-wrapper > .local-cgt-banner');
    if (!banner) {
        return;
    }
    const region = document.getElementById('region-main');
    if (!region) {
        banner.classList.add('local-cgt-banner-ready');
        return;
    }
    const notifications = document.getElementById('user-notifications');
    if (notifications && notifications.parentNode === region) {
        notifications.after(banner);
    } else {
        region.prepend(banner);
    }
};
