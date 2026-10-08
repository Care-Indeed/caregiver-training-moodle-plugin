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
 * Transactional outbox for adapter events. Each event has a stable id so the adapter can
 * de-duplicate retries; delivery is HMAC-signed and retried with exponential backoff.
 *
 * @package    local_caregivertraining
 * @copyright  2026 CI Institute of Nursing
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outbox {
    /** @var \Closure|null Test seam replacing the HTTP call: fn(string $url, array $headers, string $body): int status. */
    public static ?\Closure $transport = null;

    /**
     * Enqueue an event once per cycle and type.
     *
     * @param \stdClass $cycle
     * @param string $type
     * @param array $data
     * @return \stdClass outbox row
     */
    public static function enqueue(\stdClass $cycle, string $type, array $data): \stdClass {
        global $DB;
        if ($existing = $DB->get_record('local_cgt_outbox', ['cycleid' => $cycle->id, 'eventtype' => $type])) {
            return $existing;
        }
        $eventid = \core\uuid::generate();
        $now = time();
        $envelope = [
            'contract' => 'caregivertraining.' . config::CONTRACT_VERSION,
            'type' => $type,
            'eventid' => $eventid,
            'occurredat' => (new \DateTimeImmutable('@' . $now))->format(DATE_ATOM),
            'source' => (new \moodle_url('/'))->out(false),
            'data' => $data,
        ];
        $row = (object) [
            'cycleid' => $cycle->id,
            'eventtype' => $type,
            'eventid' => $eventid,
            'payload' => json_encode($envelope, JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'nextattempt' => $now,
            'timecreated' => $now,
        ];
        $row->id = $DB->insert_record('local_cgt_outbox', $row);
        return $row;
    }

    /**
     * Signature header value: v1=hex(HMAC-SHA256(secret, timestamp + "." + body)).
     *
     * @param string $secret
     * @param int $timestamp
     * @param string $body
     * @return string
     */
    public static function sign(string $secret, int $timestamp, string $body): string {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Deliver due events.
     *
     * @param int|null $now
     * @return array{enabled: bool, delivered: int, retrying: int, failed: int}
     */
    public static function deliver_pending(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $result = ['enabled' => config::adapter_enabled(), 'delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $url = config::adapter_url();
        $secret = config::adapter_secret();
        if (!$result['enabled']) {
            return $result;
        }
        if ($secret === '' || !config::adapter_url_valid($url)) {
            exceptions::raise('adapter_not_configured', ['key' => 'outbox']);
            return $result;
        }

        $rows = $DB->get_records_select(
            'local_cgt_outbox',
            "status = 'pending' AND nextattempt <= :now",
            ['now' => $now],
            'nextattempt ASC, id ASC',
            '*',
            0,
            100
        );
        foreach ($rows as $row) {
            $lock = locks::try_acquire('outbox:' . $row->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                $row = $DB->get_record('local_cgt_outbox', ['id' => $row->id]);
                if (!$row || $row->status !== 'pending') {
                    continue;
                }
                $timestamp = time();
                $headers = [
                    'Content-Type' => 'application/json',
                    'X-CGT-Event-Id' => $row->eventid,
                    'X-CGT-Event-Type' => $row->eventtype,
                    'X-CGT-Timestamp' => (string) $timestamp,
                    'X-CGT-Signature' => self::sign($secret, $timestamp, $row->payload),
                ];
                $status = 0;
                $error = '';
                try {
                    $status = self::send($url, $headers, $row->payload);
                } catch (\Throwable $e) {
                    $error = get_class($e);
                }
                $row->attempts++;
                // 2xx delivered; 409 means the adapter already processed this event id.
                if (($status >= 200 && $status < 300) || $status === 409) {
                    $row->status = 'delivered';
                    $row->timedelivered = time();
                    $row->lasterror = null;
                    $result['delivered']++;
                } else {
                    $row->lasterror = $error !== '' ? $error : 'HTTP ' . $status;
                    if ($row->attempts >= config::max_attempts()) {
                        $row->status = 'failed';
                        $result['failed']++;
                        exceptions::raise(
                            'outbox_failed',
                            ['key' => $row->eventid, 'error' => $row->lasterror],
                            null,
                            null,
                            (int) $row->cycleid
                        );
                    } else {
                        $row->nextattempt = $now + min(6 * HOURSECS, 60 * (2 ** min($row->attempts, 10)));
                        $result['retrying']++;
                    }
                }
                $DB->update_record('local_cgt_outbox', $row);
            } finally {
                $lock->release();
            }
        }
        return $result;
    }

    /**
     * Re-queue failed events without changing their event id.
     *
     * @param int $cycleid local cycle id
     * @return int requeued count
     */
    public static function requeue_failed(int $cycleid): int {
        global $DB;
        $rows = $DB->get_records('local_cgt_outbox', ['cycleid' => $cycleid, 'status' => 'failed']);
        foreach ($rows as $row) {
            $DB->update_record('local_cgt_outbox', (object) ['id' => $row->id, 'status' => 'pending', 'attempts' => 0,
                'nextattempt' => time()]);
        }
        return count($rows);
    }

    /**
     * POST the event. Secrets travel only in the signature header, never in the URL.
     *
     * @param string $url
     * @param array $headers
     * @param string $body
     * @return int HTTP status
     */
    private static function send(string $url, array $headers, string $body): int {
        if (self::$transport) {
            return (int) (self::$transport)($url, $headers, $body);
        }
        $client = new \core\http_client(['timeout' => 15, 'http_errors' => false]);
        $response = $client->post($url, ['headers' => $headers, 'body' => $body]);
        return $response->getStatusCode();
    }
}
