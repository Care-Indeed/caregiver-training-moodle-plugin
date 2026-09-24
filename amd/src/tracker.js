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
 * Engagement heartbeat. The browser only reports state; the server decides what is credited.
 *
 * @module     local_caregivertraining/tracker
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

let started = false;

const randomToken = () => {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
};

const mediaPlaying = () => Array.from(document.querySelectorAll('video, audio'))
    .some((m) => !m.paused && !m.ended && m.readyState > 2);

/**
 * Start sending heartbeats for the current activity page.
 *
 * @param {Object} config
 * @param {Number} config.cmid
 * @param {Number} config.interval seconds
 */
export const init = ({cmid, interval}) => {
    if (started || !cmid) {
        return;
    }
    started = true;
    const token = randomToken();
    let lastInteraction = Date.now();
    let timer = null;
    let stopped = false;

    const touch = () => {
        lastInteraction = Date.now();
    };
    ['pointerdown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach((type) =>
        document.addEventListener(type, touch, {passive: true, capture: true}));
    document.addEventListener('play', touch, true);

    const beat = () => {
        if (stopped) {
            return;
        }
        Ajax.call([{
            methodname: 'local_caregivertraining_v1_record_heartbeat',
            args: {
                cmid,
                sessiontoken: token,
                visible: document.visibilityState === 'visible',
                playing: mediaPlaying(),
                interactedago: Math.round((Date.now() - lastInteraction) / 1000),
            },
        }], true, true, true)[0].then((result) => {
            if (result.reason === 'no_open_cycle' || result.reason === 'activity_unavailable'
                    || result.reason === 'token_mismatch') {
                stopped = true;
                window.clearInterval(timer);
            }
            return result;
        }).catch(() => {
            // Network errors earn no credit; the next beat after a gap is rejected by the server.
        });
    };

    beat();
    timer = window.setInterval(beat, Math.max(10, interval) * 1000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            touch();
        }
        beat();
    });
};
