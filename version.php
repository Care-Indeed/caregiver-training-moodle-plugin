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
 * Caregiver annual training plugin version.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_caregivertraining';
$plugin->version = 2026092601;
$plugin->requires = 2025100600;
$plugin->supported = [501, 501];
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.1';
$plugin->dependencies = [
    'mod_customcert' => ANY_VERSION,
    'mod_lesson' => ANY_VERSION,
    'mod_quiz' => ANY_VERSION,
    'mod_feedback' => ANY_VERSION,
];
