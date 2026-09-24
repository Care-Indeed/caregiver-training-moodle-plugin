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
 * External functions and the adapter service. Function names carry the contract version.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_caregivertraining_v1_get_binding' => [
        'classname' => \local_caregivertraining\external\v1_get_binding::class,
        'description' => 'Look up the Moodle binding for an AlayaCare employee without changing data.',
        'type' => 'read',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_provision_learner' => [
        'classname' => \local_caregivertraining\external\v1_provision_learner::class,
        'description' => 'Idempotently create or link a learner and update protected employee fields.',
        'type' => 'write',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_upsert_cycle' => [
        'classname' => \local_caregivertraining\external\v1_upsert_cycle::class,
        'description' => 'Idempotently create, update or start an annual cycle and apply the enrolment window.',
        'type' => 'write',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_update_access' => [
        'classname' => \local_caregivertraining\external\v1_update_access::class,
        'description' => 'Apply adapter-decided employment, enrolment and reminder state to a cycle.',
        'type' => 'write',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_reconcile' => [
        'classname' => \local_caregivertraining\external\v1_reconcile::class,
        'description' => 'Return authoritative cycle state and optionally re-queue undelivered events.',
        'type' => 'write',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_health' => [
        'classname' => \local_caregivertraining\external\v1_health::class,
        'description' => 'Return configuration and queue health without personal data.',
        'type' => 'read',
        'capabilities' => 'local/caregivertraining:adapterapi',
    ],
    'local_caregivertraining_v1_record_heartbeat' => [
        'classname' => \local_caregivertraining\external\v1_record_heartbeat::class,
        'description' => 'Record a learner activity heartbeat; the server decides what time is credited.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/caregivertraining:recordtime',
    ],
];

$services = [
    'Caregiver training adapter' => [
        'shortname' => 'local_caregivertraining_adapter',
        'functions' => [
            'local_caregivertraining_v1_get_binding',
            'local_caregivertraining_v1_provision_learner',
            'local_caregivertraining_v1_upsert_cycle',
            'local_caregivertraining_v1_update_access',
            'local_caregivertraining_v1_reconcile',
            'local_caregivertraining_v1_health',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
