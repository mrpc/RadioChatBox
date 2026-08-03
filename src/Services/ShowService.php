<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Radio show schedule. A show is either recurring (a weekday + start time, repeats
 * weekly) or one-off (a specific date). The service does CRUD plus `upcoming()`,
 * which projects the next occurrences across both kinds, ordered by when they next
 * air. Times are interpreted in the app timezone (TZ env, default Europe/Athens).
 */
class ShowService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens');
    }

    /**
     * Create a show. $data: title (required), description, host, is_recurring,
     * day_of_week (0-6, recurring), show_date (Y-m-d, one-off), start_time (HH:MM),
     * end_time. Returns the new id.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $row = $this->sanitize($data);
        $result = $this->db->queryBuilder()->from('shows')->returning('id')->insert($row);
        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /**
     * Update a show. Returns whether the id was valid.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('shows')->where('id', '=', $id)->update($this->sanitize($data));
        return true;
    }

    /** Delete a show. */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('shows')->where('id', '=', $id)->delete();
        return true;
    }

    /**
     * All shows (admin list), recurring first (by weekday/time) then one-off (by
     * date). Includes inactive ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->queryBuilder()
            ->from('shows')
            ->orderBy('is_recurring', 'desc')
            ->orderBy('day_of_week', 'asc')
            ->orderBy('show_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->getAll();
    }

    /**
     * The next $limit upcoming show occurrences from $now, each with a concrete
     * `next_start` (ISO 8601). Recurring shows project to their next weekday; one-off
     * shows are included only if still in the future. Inactive shows are excluded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(\DateTimeImmutable $now, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->db->queryBuilder()->from('shows')->where('is_active', '=', true)->getAll();

        $occurrences = [];
        foreach ($rows as $show) {
            $next = $this->nextOccurrence($show, $now);
            if ($next !== null) {
                $show['next_start'] = $next->format('c');
                $show['next_start_ts'] = $next->getTimestamp();
                $occurrences[] = $show;
            }
        }

        usort($occurrences, static fn ($a, $b): int => $a['next_start_ts'] <=> $b['next_start_ts']);
        return array_slice($occurrences, 0, $limit);
    }

    /**
     * The next air datetime for a show at/after $now, or null (a past one-off).
     *
     * @param array<string,mixed> $show
     */
    private function nextOccurrence(array $show, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $tz = $this->tz();
        $now = $now->setTimezone($tz);
        $startTime = (string) ($show['start_time'] ?? '00:00:00');
        [$h, $m] = array_pad(array_map('intval', explode(':', $startTime)), 2, 0);

        if (empty($show['is_recurring'])) {
            $date = (string) ($show['show_date'] ?? '');
            if ($date === '') {
                return null;
            }
            $dt = (new \DateTimeImmutable($date, $tz))->setTime($h, $m);
            return $dt >= $now ? $dt : null;
        }

        // Recurring: find the next date matching day_of_week at start time.
        $targetDow = (int) ($show['day_of_week'] ?? 0); // 0=Sun .. 6=Sat
        $candidate = $now->setTime($h, $m);
        for ($i = 0; $i < 8; $i++) {
            $day = $candidate->modify("+{$i} day");
            if ((int) $day->format('w') === $targetDow && $day >= $now) {
                return $day;
            }
        }
        return null;
    }

    /**
     * Normalise/validate incoming show data into a DB row.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }
        $isRecurring = !array_key_exists('is_recurring', $data)
            || in_array(strtolower((string) $data['is_recurring']), ['1', 'true', 'on', 'yes'], true)
            || $data['is_recurring'] === true;

        $row = [
            'title'        => mb_substr($title, 0, 200),
            'description'  => isset($data['description']) && trim((string) $data['description']) !== ''
                ? mb_substr(trim((string) $data['description']), 0, 2000) : null,
            'host'         => isset($data['host']) && trim((string) $data['host']) !== ''
                ? mb_substr(trim((string) $data['host']), 0, 150) : null,
            'is_recurring' => $isRecurring,
            'start_time'   => $this->normaliseTime((string) ($data['start_time'] ?? '00:00')),
            'end_time'     => isset($data['end_time']) && trim((string) $data['end_time']) !== ''
                ? $this->normaliseTime((string) $data['end_time']) : null,
            'is_active'    => !array_key_exists('is_active', $data)
                || in_array(strtolower((string) $data['is_active']), ['1', 'true', 'on', 'yes'], true)
                || $data['is_active'] === true,
            'archive_url'  => isset($data['archive_url']) && trim((string) $data['archive_url']) !== ''
                ? mb_substr(trim((string) $data['archive_url']), 0, 500) : null,
        ];

        if ($isRecurring) {
            $dow = (int) ($data['day_of_week'] ?? 0);
            $row['day_of_week'] = max(0, min(6, $dow));
            $row['show_date'] = null;
        } else {
            $row['day_of_week'] = null;
            $date = trim((string) ($data['show_date'] ?? ''));
            if ($date === '') {
                throw new \InvalidArgumentException('show_date is required for a one-off show');
            }
            $row['show_date'] = $date;
        }

        return $row;
    }

    /** Coerce "H:M" / "H:M:S" to a HH:MM:SS string. */
    private function normaliseTime(string $time): string
    {
        $parts = array_map('intval', explode(':', trim($time)));
        $h = max(0, min(23, $parts[0] ?? 0));
        $m = max(0, min(59, $parts[1] ?? 0));
        return sprintf('%02d:%02d:00', $h, $m);
    }
}
