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
 * Named cross-process locks using the core lock factory.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locks {
    /** @var array<string,\core\lock\lock> Locks held by this request, for re-entrancy. */
    private static array $held = [];

    /**
     * Acquire a lock or throw. Re-entrant within one request.
     *
     * @param string $resource
     * @param int $timeout seconds to wait
     * @return lock_handle
     */
    public static function acquire(string $resource, int $timeout = 30): lock_handle {
        $resource = substr(hash('sha256', $resource), 0, 40);
        if (isset(self::$held[$resource])) {
            return new lock_handle(null, $resource);
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_caregivertraining');
        $lock = $factory->get_lock($resource, $timeout);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        self::$held[$resource] = $lock;
        return new lock_handle($lock, $resource);
    }

    /**
     * Try to acquire without throwing.
     *
     * @param string $resource
     * @param int $timeout
     * @return lock_handle|null
     */
    public static function try_acquire(string $resource, int $timeout = 2): ?lock_handle {
        try {
            return self::acquire($resource, $timeout);
        } catch (\moodle_exception $e) {
            return null;
        }
    }

    /**
     * Forget a released lock.
     *
     * @param string $resource hashed resource
     */
    public static function forget(string $resource): void {
        unset(self::$held[$resource]);
    }
}
