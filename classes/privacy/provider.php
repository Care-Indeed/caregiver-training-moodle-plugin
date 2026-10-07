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

namespace local_caregivertraining\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider. All data lives in the system context. Deletion honours the compliance
 * retention period: records still inside it are kept (see privacy:retained).
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_cgt_binding', [
            'userid' => 'privacy:metadata:local_cgt_binding:userid',
            'alayacareid' => 'privacy:metadata:local_cgt_binding:alayacareid',
            'payrollnumber' => 'privacy:metadata:local_cgt_binding:payrollnumber',
        ], 'privacy:metadata:local_cgt_binding');
        $collection->add_database_table('local_cgt_cycle', [
            'userid' => 'privacy:metadata:local_cgt_cycle:userid',
            'cycleid' => 'privacy:metadata:local_cgt_cycle:cycleid',
            'anniversarydate' => 'privacy:metadata:local_cgt_cycle:anniversarydate',
            'timecompleted' => 'privacy:metadata:local_cgt_cycle:timecompleted',
            'approvedseconds' => 'privacy:metadata:local_cgt_cycle:approvedseconds',
        ], 'privacy:metadata:local_cgt_cycle');
        $collection->add_database_table('local_cgt_snapshot', [
            'userid' => 'privacy:metadata:local_cgt_snapshot:userid',
            'payload' => 'privacy:metadata:local_cgt_snapshot:payload',
        ], 'privacy:metadata:local_cgt_snapshot');
        $collection->add_database_table('local_cgt_timesession', [
            'userid' => 'privacy:metadata:local_cgt_timesession:userid',
            'creditedseconds' => 'privacy:metadata:local_cgt_timesession:creditedseconds',
        ], 'privacy:metadata:local_cgt_timesession');
        $collection->add_database_table('local_cgt_notification', [
            'type' => 'privacy:metadata:local_cgt_notification:type',
            'timesent' => 'privacy:metadata:local_cgt_notification:timesent',
        ], 'privacy:metadata:local_cgt_notification');
        $collection->add_database_table('local_cgt_exception', [
            'userid' => 'privacy:metadata:local_cgt_exception:userid',
            'alayacareid' => 'privacy:metadata:local_cgt_exception:alayacareid',
            'details' => 'privacy:metadata:local_cgt_exception:details',
        ], 'privacy:metadata:local_cgt_exception');
        $collection->add_database_table('local_cgt_outbox', [
            'payload' => 'privacy:metadata:local_cgt_outbox:payload',
        ], 'privacy:metadata:local_cgt_outbox');
        $collection->add_database_table('local_cgt_request', [
            'response' => 'privacy:metadata:local_cgt_request:response',
        ], 'privacy:metadata:local_cgt_request');
        $collection->add_external_location_link('adapter', [
            'alayacareid' => 'privacy:metadata:adapter:alayacareid',
            'cycleid' => 'privacy:metadata:adapter:cycleid',
            'completedat' => 'privacy:metadata:adapter:completedat',
            'certificatecode' => 'privacy:metadata:adapter:certificatecode',
        ], 'privacy:metadata:adapter');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:local_cgt_snapshot');
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_cgt_binding', ['userid' => $userid])
                || $DB->record_exists('local_cgt_cycle', ['userid' => $userid])
                || $DB->record_exists('local_cgt_exception', ['userid' => $userid])
        ) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_cgt_binding}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_cgt_cycle}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_cgt_exception} WHERE userid IS NOT NULL', []);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $subcontext = [get_string('pluginname', 'local_caregivertraining')];
        $binding = $DB->get_record(
            'local_cgt_binding',
            ['userid' => $userid],
            'alayacareid, payrollnumber, status, timecreated'
        );
        $cycles = array_values($DB->get_records(
            'local_cgt_cycle',
            ['userid' => $userid],
            'timedue',
            'id, cycleid, anniversarydate, hiredate, status, timecompleted, approvedseconds, timepolicy, certificatecode'
        ));
        foreach ($cycles as $cycle) {
            $cycle->timesessions = array_values($DB->get_records(
                'local_cgt_timesession',
                ['cycleid' => $cycle->id],
                'id',
                'cmid, timestarted, timelastbeat, beats, rejectedbeats, creditedseconds'
            ));
            $cycle->snapshots = array_values($DB->get_records(
                'local_cgt_snapshot',
                ['cycleid' => $cycle->id],
                'id',
                'type, payloadhash, verified, timecreated, retainuntil'
            ));
            $cycle->notifications = array_values($DB->get_records(
                'local_cgt_notification',
                ['cycleid' => $cycle->id],
                'id',
                'type, occurrence, status, timesent'
            ));
            unset($cycle->id);
        }
        $exceptions = array_values($DB->get_records(
            'local_cgt_exception',
            ['userid' => $userid],
            'id',
            'type, status, details, timecreated, timemodified'
        ));
        writer::with_context($context)->export_data($subcontext, (object) ['binding' => $binding, 'cycles' => $cycles,
            'exceptions' => $exceptions]);
    }

    /**
     * Delete all users' data in a context (only data past retention).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($DB->get_fieldset_sql('SELECT DISTINCT userid FROM {local_cgt_cycle}') as $userid) {
            self::delete_expired_for_user((int) $userid);
        }
    }

    /**
     * Delete a user's data (only data past retention).
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::delete_expired_for_user((int) $contextlist->get_user()->id);
        }
    }

    /**
     * Delete several users' data (only data past retention).
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_expired_for_user((int) $userid);
        }
    }

    /**
     * Remove cycles whose every snapshot is past retention. Cycles still in the retention period,
     * and the binding while any cycle remains, are kept to meet the compliance obligation.
     *
     * @param int $userid
     */
    protected static function delete_expired_for_user(int $userid): void {
        global $DB;
        $now = time();
        $fs = get_file_storage();
        $systemcontext = \context_system::instance();
        foreach ($DB->get_records('local_cgt_cycle', ['userid' => $userid]) as $cycle) {
            $snapshots = $DB->get_records('local_cgt_snapshot', ['cycleid' => $cycle->id]);
            $retained = $cycle->status !== 'completed' && $cycle->status !== 'superseded';
            $cycleretainuntil = strtotime(
                '+' . \local_caregivertraining\local\config::retention_years() . ' years',
                (int) ($cycle->timecompleted ?: $cycle->timemodified)
            );
            if ($cycleretainuntil > $now) {
                $retained = true;
            }
            foreach ($snapshots as $snapshot) {
                if ((int) $snapshot->retainuntil > $now) {
                    $retained = true;
                }
            }
            if ($retained) {
                continue;
            }
            foreach ($snapshots as $snapshot) {
                $fs->delete_area_files($systemcontext->id, 'local_caregivertraining', 'snapshotcert', $snapshot->id);
            }
            $DB->delete_records('local_cgt_snapshot', ['cycleid' => $cycle->id]);
            $DB->delete_records('local_cgt_timesession', ['cycleid' => $cycle->id]);
            $DB->delete_records('local_cgt_notification', ['cycleid' => $cycle->id]);
            $DB->delete_records('local_cgt_outbox', ['cycleid' => $cycle->id]);
            $DB->delete_records('local_cgt_cycle', ['id' => $cycle->id]);
        }
        if (!$DB->record_exists('local_cgt_cycle', ['userid' => $userid])) {
            $DB->delete_records('local_cgt_binding', ['userid' => $userid]);
            $DB->delete_records('local_cgt_exception', ['userid' => $userid]);
        }
    }
}
