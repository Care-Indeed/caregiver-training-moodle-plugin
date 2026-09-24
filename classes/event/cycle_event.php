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

namespace local_caregivertraining\event;

/**
 * Base for plugin events. These are recorded in Moodle's standard log, which is the audit trail
 * for administrative and system actions.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class cycle_event extends \core\event\base {
    /** @var string Language string key for the event name. */
    protected const NAME = '';

    /** @var string CRUD letter. */
    protected const CRUD = 'u';

    /** @var string Object table. */
    protected const OBJECTTABLE = 'local_cgt_cycle';

    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = static::CRUD;
        $this->data['edulevel'] = self::LEVEL_OTHER;
        if (static::OBJECTTABLE !== '') {
            $this->data['objecttable'] = static::OBJECTTABLE;
        }
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string(static::NAME, 'local_caregivertraining');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        $other = $this->other ? json_encode($this->other) : '';
        return "User {$this->userid} performed '" . static::NAME . "' on {$this->objecttable} {$this->objectid}"
            . ($this->relateduserid ? " for user {$this->relateduserid}" : '') . ". {$other}";
    }

    /**
     * Object id mapping for backup/restore (not restored).
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => static::OBJECTTABLE, 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
