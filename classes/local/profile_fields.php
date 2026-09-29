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
 * Protected custom profile fields managed through the core profile API.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_fields {
    /** @var string AlayaCare external_id. */
    const EXTERNALID = 'cgt_alayacare_externalid';
    /** @var string Employee ID / payroll number. */
    const PAYROLLID = 'cgt_payrollid';
    /** @var string HCA number. */
    const HCANUMBER = 'cgt_hcanumber';
    /** @var string HCA registration date (supplied by the adapter; never calculated here). */
    const REGISTRATIONDATE = 'cgt_hcaregistrationdate';

    /**
     * Field definitions.
     *
     * @return array
     */
    public static function definitions(): array {
        return [
            self::EXTERNALID => ['datatype' => 'text', 'name' => 'field_externalid', 'forceunique' => 1],
            self::PAYROLLID => ['datatype' => 'text', 'name' => 'field_payrollid', 'forceunique' => 1],
            self::HCANUMBER => ['datatype' => 'text', 'name' => 'field_hcanumber', 'forceunique' => 0],
            self::REGISTRATIONDATE => ['datatype' => 'datetime', 'name' => 'field_registrationdate', 'forceunique' => 0],
        ];
    }

    /**
     * Create missing fields. Existing fields are left untouched so admin edits are preserved.
     */
    public static function ensure(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/user/profile/definelib.php');

        $categoryname = get_string('profilecategory', config::COMPONENT);
        $category = $DB->get_record('user_info_category', ['name' => $categoryname]);
        if (!$category) {
            $data = (object) ['name' => $categoryname];
            profile_save_category($data);
            $category = $DB->get_record('user_info_category', ['name' => $categoryname], '*', MUST_EXIST);
        }

        foreach (self::definitions() as $shortname => $definition) {
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }
            $data = (object) [
                'id' => 0,
                'shortname' => $shortname,
                'name' => get_string($definition['name'], config::COMPONENT),
                'datatype' => $definition['datatype'],
                'description' => ['text' => get_string('field_desc', config::COMPONENT), 'format' => FORMAT_HTML],
                'categoryid' => $category->id,
                'required' => 0,
                // Locked: only users with moodle/user:update (admins/adapter code) can change it; learners cannot.
                'locked' => 1,
                // Visible to the user and to staff who can view profiles.
                'visible' => PROFILE_VISIBLE_ALL,
                'forceunique' => $definition['forceunique'],
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_MOODLE,
                'param1' => $definition['datatype'] === 'datetime' ? '2000' : '30',
                'param2' => $definition['datatype'] === 'datetime' ? '2100' : '100',
                'param3' => $definition['datatype'] === 'datetime' ? '0' : '0',
                'param4' => '',
                'param5' => '',
            ];
            profile_save_field($data, []);
        }
    }

    /**
     * Convert YYYY-MM-DD to noon UTC so the displayed calendar day is stable across US timezones.
     *
     * @param string $date
     * @return int
     */
    public static function date_to_timestamp(string $date): int {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 12:00', new \DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : 0;
    }

    /**
     * Save integration-owned profile values through the core API.
     *
     * @param int $userid
     * @param array $values shortname => value (null = leave unchanged)
     */
    public static function save(int $userid, array $values): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $data = [];
        foreach ($values as $shortname => $value) {
            if ($value === null) {
                continue;
            }
            if ($shortname === self::REGISTRATIONDATE) {
                $value = $value === '' ? 0 : self::date_to_timestamp($value);
            }
            $data[$shortname] = $value;
        }
        if ($data) {
            profile_save_custom_fields($userid, $data);
        }
    }

    /**
     * Load integration profile values.
     *
     * @param int $userid
     * @return array shortname => value
     */
    public static function load(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $record = profile_user_record($userid, false);
        $result = [];
        foreach (array_keys(self::definitions()) as $shortname) {
            $result[$shortname] = $record->{$shortname} ?? null;
        }
        $date = (int) ($result[self::REGISTRATIONDATE] ?? 0);
        $result[self::REGISTRATIONDATE] = $date ? gmdate('Y-m-d', $date) : null;
        return $result;
    }

    /**
     * Names accepted by {@see \core_user\fields::including()}.
     *
     * @return string[]
     */
    public static function query_names(): array {
        return array_map(
            fn(string $shortname): string => \core_user\fields::PROFILE_FIELD_PREFIX . $shortname,
            array_keys(self::definitions())
        );
    }

    /**
     * Name fields plus the caregiver employment profile fields.
     *
     * @return \core_user\fields
     */
    public static function user_fields(): \core_user\fields {
        return \core_user\fields::for_name()->including(...self::query_names());
    }

    /**
     * Employment values from a row selected with {@see user_fields()}.
     *
     * The registration date is YYYY-MM-DD. Text fields are trimmed. Missing values are empty strings.
     *
     * @param \stdClass $record
     * @return array{externalid: string, payrollid: string, hcanumber: string, registrationdate: string}
     */
    public static function values_from_record(\stdClass $record): array {
        $text = function (string $shortname) use ($record): string {
            $property = \core_user\fields::PROFILE_FIELD_PREFIX . $shortname;
            return trim((string) ($record->{$property} ?? ''));
        };
        $rawdate = $text(self::REGISTRATIONDATE);
        $registrationdate = '';
        if ($rawdate !== '' && is_numeric($rawdate)) {
            $timestamp = (int) $rawdate;
            $registrationdate = $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : '';
        }
        return [
            'externalid' => $text(self::EXTERNALID),
            'payrollid' => $text(self::PAYROLLID),
            'hcanumber' => $text(self::HCANUMBER),
            'registrationdate' => $registrationdate,
        ];
    }
}
