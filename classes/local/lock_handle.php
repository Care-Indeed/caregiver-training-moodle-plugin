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
 * Releasable handle; nested acquisitions hold a null lock and release nothing.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lock_handle {
    /** @var \core\lock\lock|null Underlying lock; null for a re-entrant handle. */
    private ?\core\lock\lock $lock;

    /** @var string Resource name. */
    private string $resource;

    /**
     * Constructor.
     *
     * @param \core\lock\lock|null $lock
     * @param string $resource
     */
    public function __construct(?\core\lock\lock $lock, string $resource) {
        $this->lock = $lock;
        $this->resource = $resource;
    }

    /**
     * Release the lock if this handle owns it.
     */
    public function release(): void {
        if ($this->lock) {
            $this->lock->release();
            $this->lock = null;
            locks::forget($this->resource);
        }
    }
}
