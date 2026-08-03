<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Show reminders: listeners subscribe to a show and get an in-app notification a
 * short window before it next airs. `sendDueReminders()` is driven by the
 * scheduler; it de-duplicates per (show, occurrence) via a cache marker so each
 * airing reminds a subscriber at most once.
 */
class ShowSubscriptionService
{
    /** Remind subscribers when a show is within this many minutes of airing. */
    private const LEAD_MINUTES = 15;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /** Subscribe or unsubscribe a user to a show. Returns the new state (subscribed?). */
    public function setSubscribed(string $username, int $showId, bool $subscribe): bool
    {
        $username = trim($username);
        if ($username === '' || $showId <= 0) {
            return false;
        }
        if ($subscribe) {
            try {
                $this->db->preparedQuery(
                    'INSERT INTO show_subscriptions (username, show_id)
                     VALUES (:u, :s)
                     ON CONFLICT (username, show_id) DO NOTHING',
                    ['u' => mb_substr($username, 0, 100), 's' => $showId]
                );
            } catch (\Throwable $e) {
                // Non-fatal.
            }
            return true;
        }
        $this->db->queryBuilder()->from('show_subscriptions')
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->where('show_id', '=', $showId)
            ->delete();
        return false;
    }

    /** The set of show ids a user is subscribed to. @return list<int> */
    public function subscribedShowIds(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return [];
        }
        $rows = $this->db->queryBuilder()->from('show_subscriptions')
            ->select(['show_id'])
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->getAll();
        return array_map(static fn ($r): int => (int) $r['show_id'], $rows);
    }

    /**
     * Notify subscribers of any show airing within the next LEAD_MINUTES that
     * hasn't reminded for this occurrence yet. Returns how many notifications were
     * created. `$now` is injectable for deterministic tests.
     */
    public function sendDueReminders(?\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $shows = (new ShowService())->upcoming($now, 100);
        $notifier = new NotificationService();
        $sent = 0;

        foreach ($shows as $show) {
            $start = new \DateTimeImmutable((string) $show['next_start']);
            $minutesUntil = ($start->getTimestamp() - $now->getTimestamp()) / 60;
            if ($minutesUntil < 0 || $minutesUntil > self::LEAD_MINUTES) {
                continue;
            }

            $showId = (int) $show['id'];
            $occurrence = $start->format('Ymd_Hi');
            $marker = "show_reminder:{$showId}:{$occurrence}";
            try {
                // Claim this occurrence once (atomic INCR; first caller gets 1).
                if (FlatCache::default()->increment($marker, 1, 86400) !== 1) {
                    continue;
                }
            } catch (\Throwable $e) {
                // If the cache can't guard, fall through (better a dup than silence).
            }

            $subs = $this->db->queryBuilder()->from('show_subscriptions')
                ->select(['username'])
                ->where('show_id', '=', $showId)
                ->getAll();
            $when = $start->format('H:i');
            foreach ($subs as $sub) {
                $notifier->add(
                    (string) $sub['username'],
                    'show',
                    '📻 ' . ((string) $show['title']) . ' airs at ' . $when,
                    !empty($show['host']) ? 'with ' . $show['host'] : null
                );
                $sent++;
            }
        }

        return $sent;
    }
}
