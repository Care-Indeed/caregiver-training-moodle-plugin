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
 * Identity binding. The canonical immutable key is the AlayaCare employee id; External ID and
 * payroll number are separate attributes that must be unique when present but can change.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class binding_manager {
    /**
     * Validate and normalise the canonical id.
     *
     * @param string $alayacareid
     * @return string
     */
    public static function normalise_id(string $alayacareid): string {
        $alayacareid = trim($alayacareid);
        if ($alayacareid === '' || strlen($alayacareid) > 64 || !preg_match('/^[A-Za-z0-9._\-]+$/', $alayacareid)) {
            throw new \invalid_parameter_exception('alayacareid must be 1-64 characters of [A-Za-z0-9._-]');
        }
        return $alayacareid;
    }

    /**
     * Normalise an optional attribute (null = not supplied, '' = clear).
     *
     * @param string|null $value
     * @return string|null
     */
    private static function attr(?string $value): ?string {
        return $value === null ? null : trim($value);
    }

    /**
     * Find a binding by canonical id.
     *
     * @param string $alayacareid
     * @return \stdClass|null
     */
    public static function get_by_alayacareid(string $alayacareid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_cgt_binding', ['alayacareid' => $alayacareid]) ?: null;
    }

    /**
     * Find a binding by Moodle user.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_by_userid(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_cgt_binding', ['userid' => $userid]) ?: null;
    }

    /**
     * Active (non-deleted) users with this email, compared case-insensitively.
     *
     * @param string $email
     * @return \stdClass[]
     */
    public static function users_by_email(string $email): array {
        global $DB;
        if (trim($email) === '') {
            return [];
        }
        $select = $DB->sql_equal('email', ':email', false, false) . ' AND deleted = 0 AND mnethostid = :mnethostid';
        return array_values($DB->get_records_select(
            'user',
            $select,
            ['email' => trim($email), 'mnethostid' => $GLOBALS['CFG']->mnet_localhost_id],
            'id',
            'id, username, email, suspended'
        ));
    }

    /**
     * Read-only lookup used by the adapter before deciding what to do.
     *
     * @param array $query alayacareid, externalid, payrollid, email, userid (any subset)
     * @return array
     */
    public static function lookup(array $query): array {
        global $DB;
        $matches = [];
        $add = function (?\stdClass $binding, string $via) use (&$matches) {
            if ($binding) {
                $matches[$binding->id] = $matches[$binding->id] ?? ['binding' => $binding, 'via' => []];
                $matches[$binding->id]['via'][] = $via;
            }
        };
        if (!empty($query['alayacareid'])) {
            $add(self::get_by_alayacareid(self::normalise_id($query['alayacareid'])), 'alayacareid');
        }
        if (!empty($query['userid'])) {
            $add(self::get_by_userid((int) $query['userid']), 'userid');
        }
        foreach (['externalid', 'payrollid'] as $attr) {
            if (!empty($query[$attr])) {
                foreach ($DB->get_records('local_cgt_binding', [$attr => trim($query[$attr])]) as $binding) {
                    $add($binding, $attr);
                }
            }
        }

        $candidates = [];
        if (!empty($query['email'])) {
            foreach (self::users_by_email($query['email']) as $user) {
                $binding = self::get_by_userid((int) $user->id);
                if ($binding) {
                    $add($binding, 'email');
                } else {
                    $candidates[] = ['userid' => (int) $user->id, 'username' => $user->username,
                        'suspended' => (bool) $user->suspended, 'via' => 'email'];
                }
            }
        }

        $bindings = array_values($matches);
        if (count($bindings) > 1) {
            $status = 'conflict';
        } else if (count($bindings) === 1) {
            $status = 'bound';
            // A bound record that disagrees with the query on the canonical id is a conflict.
            $b = $bindings[0]['binding'];
            if (!empty($query['alayacareid']) && $b->alayacareid !== trim($query['alayacareid'])) {
                $status = 'conflict';
            }
        } else if (count($candidates) > 1) {
            $status = 'ambiguous';
        } else if (count($candidates) === 1) {
            $status = 'candidate';
        } else {
            $status = 'none';
        }

        return [
            'status' => $status,
            'bindings' => array_map(fn($m) => self::export($m['binding']) + ['matchedby' => implode(',', $m['via'])], $bindings),
            'candidates' => $candidates,
        ];
    }

    /**
     * Export a binding for API responses.
     *
     * @param \stdClass $binding
     * @return array
     */
    public static function export(\stdClass $binding): array {
        $profile = profile_fields::load((int) $binding->userid);
        return [
            'bindingid' => (int) $binding->id,
            'userid' => (int) $binding->userid,
            'alayacareid' => $binding->alayacareid,
            'externalid' => (string) $binding->externalid,
            'payrollid' => (string) $binding->payrollid,
            'hcanumber' => (string) ($profile[profile_fields::HCANUMBER] ?? ''),
            'registrationdate' => (string) ($profile[profile_fields::REGISTRATIONDATE] ?? ''),
            'status' => $binding->status,
        ];
    }

    /**
     * Ensure External ID / payroll are not bound to a different employee.
     *
     * @param string $alayacareid
     * @param string|null $externalid
     * @param string|null $payrollid
     */
    private static function assert_attributes_unique(string $alayacareid, ?string $externalid, ?string $payrollid): void {
        global $DB;
        foreach (['externalid' => $externalid, 'payrollid' => $payrollid] as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $select = "{$field} = :value AND alayacareid <> :alayacareid";
            if ($DB->record_exists_select('local_cgt_binding', $select, ['value' => $value, 'alayacareid' => $alayacareid])) {
                exceptions::raise('duplicate_' . $field, ['key' => $value, 'field' => $field], null, $alayacareid);
                throw new \moodle_exception(
                    'error:bindingconflict',
                    config::COMPONENT,
                    '',
                    "{$field} is bound to another employee"
                );
            }
        }
    }

    /**
     * Idempotent create/link/update. Never auto-links by email and never creates on ambiguity.
     *
     * @param array $p validated parameters
     * @return array response
     */
    public static function provision(array $p): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $alayacareid = self::normalise_id($p['alayacareid']);
        $externalid = self::attr($p['externalid'] ?? null);
        $payrollid = self::attr($p['payrollid'] ?? null);
        $email = trim((string) ($p['email'] ?? ''));
        if ($email !== '' && !validate_email($email)) {
            throw new \moodle_exception('error:invalidemail', config::COMPONENT);
        }
        if (!empty($p['registrationdate'])) {
            cycle_manager::parse_date($p['registrationdate']);
        }

        $lock = locks::acquire('binding:' . $alayacareid);
        $emaillock = $email !== '' ? locks::acquire('email:' . \core_text::strtolower($email)) : null;
        try {
            self::assert_attributes_unique($alayacareid, $externalid, $payrollid);
            $binding = self::get_by_alayacareid($alayacareid);
            $action = 'unchanged';
            $activation = 'notrequested';

            if ($binding) {
                if (!empty($p['userid']) && (int) $p['userid'] !== (int) $binding->userid) {
                    exceptions::raise('binding_user_mismatch', ['key' => (string) $p['userid'],
                        'requesteduserid' => (int) $p['userid']], (int) $binding->userid, $alayacareid);
                    throw new \moodle_exception(
                        'error:bindingconflict',
                        config::COMPONENT,
                        '',
                        'employee is bound to a different Moodle user'
                    );
                }
                $user = $DB->get_record('user', ['id' => $binding->userid, 'deleted' => 0]);
                if (!$user) {
                    exceptions::raise('bound_user_deleted', [], (int) $binding->userid, $alayacareid);
                    throw new \moodle_exception('error:usernotfound', config::COMPONENT);
                }
                $changed = self::update_binding_attributes($binding, $externalid, $payrollid);
                $changed = self::update_user_details($user, $p, $email) || $changed;
                $action = $changed ? 'updated' : 'unchanged';
            } else {
                if (!empty($p['userid'])) {
                    $user = $DB->get_record('user', ['id' => (int) $p['userid'], 'deleted' => 0]);
                    if (!$user || isguestuser($user) || is_siteadmin($user)) {
                        throw new \moodle_exception('error:usernotfound', config::COMPONENT);
                    }
                    if ($other = self::get_by_userid((int) $user->id)) {
                        exceptions::raise(
                            'user_already_bound',
                            ['key' => $alayacareid, 'existing' => $other->alayacareid],
                            (int) $user->id,
                            $alayacareid
                        );
                        throw new \moodle_exception(
                            'error:bindingconflict',
                            config::COMPONENT,
                            '',
                            'Moodle user is bound to a different employee'
                        );
                    }
                    $action = 'linked';
                } else {
                    $matches = self::users_by_email($email);
                    if (count($matches) > 1) {
                        exceptions::raise('ambiguous_email', ['key' => \core_text::strtolower($email),
                            'userids' => array_map(fn($u) => (int) $u->id, $matches)], null, $alayacareid);
                        throw new \moodle_exception('error:ambiguousemail', config::COMPONENT);
                    }
                    if (count($matches) === 1) {
                        exceptions::raise(
                            'existing_account_unconfirmed',
                            ['key' => (string) $matches[0]->id],
                            (int) $matches[0]->id,
                            $alayacareid
                        );
                        throw new \moodle_exception('error:existingaccount', config::COMPONENT, '', (int) $matches[0]->id);
                    }
                    if (empty($p['createifmissing'])) {
                        return ['status' => 'notfound', 'userid' => 0, 'bindingid' => 0, 'activation' => 'notrequested'];
                    }
                    $firstname = trim((string) ($p['firstname'] ?? ''));
                    $lastname = trim((string) ($p['lastname'] ?? ''));
                    if ($email === '' || $firstname === '' || $lastname === '') {
                        throw new \invalid_parameter_exception('email, firstname and lastname are required to create a learner');
                    }
                    $user = self::create_user($p, $email);
                    $action = 'created';
                }

                $now = time();
                $binding = (object) [
                    'userid' => $user->id,
                    'alayacareid' => $alayacareid,
                    'externalid' => $externalid ?: null,
                    'payrollid' => $payrollid ?: null,
                    'status' => 'active',
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => $GLOBALS['USER']->id ?? 0,
                ];
                try {
                    $binding->id = $DB->insert_record('local_cgt_binding', $binding);
                } catch (\dml_write_exception $e) {
                    // Unique index on userid/alayacareid caught a concurrent duplicate.
                    throw new \moodle_exception('error:bindingconflict', config::COMPONENT, '', 'concurrent binding');
                }
            }

            profile_fields::save((int) $binding->userid, [
                profile_fields::EXTERNALID => $externalid,
                profile_fields::PAYROLLID => $payrollid,
                profile_fields::HCANUMBER => self::attr($p['hcanumber'] ?? null),
                profile_fields::REGISTRATIONDATE => self::attr($p['registrationdate'] ?? null),
            ]);

            if (!empty($p['sendactivation'])) {
                $activation = activation::queue((int) $binding->userid);
            }

            \local_caregivertraining\event\learner_provisioned::create([
                'context' => \context_system::instance(),
                'relateduserid' => (int) $binding->userid,
                'objectid' => (int) $binding->id,
                'other' => ['action' => $action],
            ])->trigger();

            return ['status' => $action, 'userid' => (int) $binding->userid, 'bindingid' => (int) $binding->id,
                'activation' => $activation];
        } finally {
            if ($emaillock) {
                $emaillock->release();
            }
            $lock->release();
        }
    }

    /**
     * Update mutable binding attributes.
     *
     * @param \stdClass $binding
     * @param string|null $externalid
     * @param string|null $payrollid
     * @return bool changed
     */
    private static function update_binding_attributes(\stdClass $binding, ?string $externalid, ?string $payrollid): bool {
        global $DB, $USER;
        $changed = false;
        foreach (['externalid' => $externalid, 'payrollid' => $payrollid] as $field => $value) {
            if ($value !== null && (string) $binding->$field !== $value) {
                $binding->$field = $value === '' ? null : $value;
                $changed = true;
            }
        }
        if ($changed) {
            $binding->timemodified = time();
            $binding->usermodified = $USER->id ?? 0;
            $DB->update_record('local_cgt_binding', $binding);
        }
        return $changed;
    }

    /**
     * Update name/email through the core user API; email changes must not collide with another account.
     *
     * @param \stdClass $user
     * @param array $p
     * @param string $email
     * @return bool changed
     */
    private static function update_user_details(\stdClass $user, array $p, string $email): bool {
        $update = ['id' => $user->id];
        foreach (['firstname', 'lastname'] as $field) {
            $value = trim((string) ($p[$field] ?? ''));
            if ($value !== '' && $value !== $user->$field) {
                $update[$field] = $value;
            }
        }
        if ($email !== '' && \core_text::strtolower($email) !== \core_text::strtolower($user->email)) {
            foreach (self::users_by_email($email) as $other) {
                if ((int) $other->id !== (int) $user->id) {
                    exceptions::raise(
                        'email_collision',
                        ['key' => \core_text::strtolower($email), 'otheruserid' => (int) $other->id],
                        (int) $user->id
                    );
                    throw new \moodle_exception('error:bindingconflict', config::COMPONENT, '', 'email belongs to another account');
                }
            }
            $update['email'] = $email;
        }
        if (count($update) === 1) {
            return false;
        }
        user_update_user((object) $update, false, true);
        return true;
    }

    /**
     * Create a manual-auth account with an unusable password; the learner sets it via the activation link.
     *
     * @param array $p
     * @param string $email
     * @return \stdClass
     */
    private static function create_user(array $p, string $email): \stdClass {
        global $CFG, $DB;
        $username = \core_text::strtolower($email);
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            throw new \moodle_exception('error:usernametaken', config::COMPONENT);
        }
        $user = (object) [
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $username,
            // Random secret nobody knows; overwritten when the learner completes the reset flow.
            'password' => random_string(40) . 'Aa1!',
            'firstname' => trim($p['firstname']),
            'lastname' => trim($p['lastname']),
            'email' => $email,
            'lang' => $CFG->lang,
            'timezone' => '99',
        ];
        $user->id = user_create_user($user, true, true);
        return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    }
}
