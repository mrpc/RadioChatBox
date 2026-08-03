<?php

namespace RadioChatBox\Services;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Scheduled promotional messages sent by fake users. A campaign fires on its
 * interval (within optional active hours) and either posts into the public chat
 * or DMs a batch of online listeners — the latter respecting a per-user cooldown
 * and a per-run recipient cap so nobody is spammed. Driven by the scheduler
 * (promo_dispatch task); every entry point takes an injectable "now" for tests.
 */
class PromoService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    // ---- CRUD -------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $row = $this->sanitize($data);
        $result = $this->db->queryBuilder()->from('promo_campaigns')->returning('id')->insert($row);
        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('promo_campaigns')->where('id', '=', $id)->update($this->sanitize($data));
        return true;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('promo_campaigns')->where('id', '=', $id)->delete();
        return true;
    }

    /** @return array<int, array<string,mixed>> */
    public function all(): array
    {
        return $this->db->queryBuilder()->from('promo_campaigns')->orderBy('created_at', 'desc')->getAll();
    }

    // ---- Dispatch ---------------------------------------------------

    /**
     * Active campaigns that are due to run at $now (interval elapsed) and inside
     * their active-hours window.
     *
     * @return array<int, array<string,mixed>>
     */
    public function dueCampaigns(\DateTimeImmutable $now): array
    {
        $rows = $this->db->queryBuilder()->from('promo_campaigns')->where('is_active', '=', true)->getAll();
        $due = [];
        foreach ($rows as $c) {
            if (!$this->withinWindow($c, $now)) {
                continue;
            }
            $interval = max(1, (int) $c['interval_minutes']) * 60;
            $last = !empty($c['last_run_at']) ? strtotime((string) $c['last_run_at']) : 0;
            if ($last === 0 || ($now->getTimestamp() - $last) >= $interval) {
                $due[] = $c;
            }
        }
        return $due;
    }

    /**
     * Run every due campaign and stamp last_run_at. Returns the total number of
     * messages sent across all campaigns.
     */
    public function dispatchDue(?\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $sent = 0;
        foreach ($this->dueCampaigns($now) as $campaign) {
            try {
                $sent += ((string) $campaign['target'] === 'dm')
                    ? $this->runDm($campaign, $now)
                    : $this->runPublic($campaign);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('PromoService: campaign ' . ($campaign['id'] ?? '?') . ' failed: ' . $e->getMessage(), 'radiochatbox');
            }
            $this->db->preparedQuery('UPDATE promo_campaigns SET last_run_at = NOW() WHERE id = :id', ['id' => (int) $campaign['id']]);
        }
        return $sent;
    }

    /** Post the campaign message to the public chat as a fake user. */
    public function runPublic(array $campaign): int
    {
        $sender = $this->resolveSender($campaign);
        if ($sender === null) {
            return 0;
        }
        (new ChatService())->postAsFakeUser($sender, (string) $campaign['message']);
        $this->addReach((int) $campaign['id'], 1);
        return 1;
    }

    /**
     * DM the campaign message to a batch of online listeners as a fake user,
     * skipping anyone still within the per-user cooldown, capped per run.
     */
    public function runDm(array $campaign, \DateTimeImmutable $now): int
    {
        $sender = $this->resolveSender($campaign);
        if ($sender === null) {
            return 0;
        }
        $cap = max(1, (int) $campaign['max_recipients']);
        $cooldown = max(0, (int) $campaign['cooldown_hours']) * 3600;
        $campaignId = (int) $campaign['id'];

        $recipients = $this->onlineRecipients($sender, $cap * 3);
        $sent = 0;
        foreach ($recipients as $r) {
            if ($sent >= $cap) {
                break;
            }
            $username = (string) $r['username'];
            $cdKey = "promo:sent:{$campaignId}:" . mb_strtolower($username);
            if ($cooldown > 0) {
                try {
                    if (FlatCache::default()->get($cdKey) !== null) {
                        continue; // still on cooldown
                    }
                } catch (\Throwable $e) { /* proceed */ }
            }
            $this->sendDm($sender, $username, (string) $r['session_id'], (string) $campaign['message']);
            if ($cooldown > 0) {
                try { FlatCache::default()->set($cdKey, 1, $cooldown); } catch (\Throwable $e) { /* ignore */ }
            }
            $sent++;
        }
        if ($sent > 0) {
            $this->addReach($campaignId, $sent);
        }
        return $sent;
    }

    // ---- Helpers ----------------------------------------------------

    /** Add to a campaign's cumulative reach (best-effort; column is additive). */
    private function addReach(int $campaignId, int $by): void
    {
        try {
            $this->db->preparedQuery(
                'UPDATE promo_campaigns SET sent_count = COALESCE(sent_count, 0) + :by WHERE id = :id',
                ['by' => $by, 'id' => $campaignId]
            );
        } catch (\Throwable $e) {
            // sent_count is analytics only; never fail delivery over it.
        }
    }

    /** The sender nickname: the campaign's fake user, or a random active bot. */
    private function resolveSender(array $campaign): ?string
    {
        $fakeUserId = (int) ($campaign['fake_user_id'] ?? 0);
        if ($fakeUserId > 0) {
            $row = $this->db->preparedQuery('SELECT nickname FROM fake_users WHERE id = :id AND is_active = TRUE LIMIT 1', ['id' => $fakeUserId]);
            $nick = $row ? $row->fetchColumn() : false;
            return ($nick === false || $nick === null) ? null : (string) $nick;
        }
        $row = $this->db->query('SELECT nickname FROM fake_users WHERE is_active = TRUE ORDER BY RANDOM() LIMIT 1');
        $nick = $row ? $row->fetchColumn() : false;
        return ($nick === false || $nick === null) ? null : (string) $nick;
    }

    /**
     * Online real users (not fake users, not the sender), most-recently-active
     * first. Capped.
     *
     * @return array<int, array{username:string, session_id:string}>
     */
    private function onlineRecipients(string $sender, int $limit): array
    {
        $rows = $this->db->preparedQuery(
            "SELECT DISTINCT ON (ps.username) ps.username, ps.session_id
             FROM presence_sessions ps
             WHERE ps.last_heartbeat > NOW() - INTERVAL '5 minutes'
               AND LOWER(ps.username) <> LOWER(:sender)
               AND ps.username NOT IN (SELECT nickname FROM fake_users)
             ORDER BY ps.username, ps.last_heartbeat DESC
             LIMIT :lim",
            ['sender' => $sender, 'lim' => max(1, $limit)]
        );
        return $rows ? $rows->fetchAll() : [];
    }

    /** Insert + broadcast a DM from a fake user to a peer (promo delivery). */
    private function sendDm(string $fromNickname, string $toUsername, string $toSessionId, string $message): void
    {
        $message = mb_substr($message, 0, 500);
        $fromSessionId = 'fake_' . md5($fromNickname);
        $result = $this->db->preparedQuery(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id, created_at',
            [$fromNickname, $fromSessionId, $toUsername, $toSessionId, $message]
        );
        $row = ($result && $result->numRows > 0) ? $result->fields : null;
        try {
            BroadcastingManager::instance()->broadcast('chat:private_messages', 'private', [
                'id'            => $row['id'] ?? null,
                'from_username' => $fromNickname,
                'to_username'   => $toUsername,
                'message'       => $message,
                'attachment'    => null,
                'timestamp'     => isset($row['created_at']) ? strtotime((string) $row['created_at']) : time(),
                'type'          => 'private',
            ]);
        } catch (\Throwable $e) {
            // best-effort broadcast; the row is persisted regardless
        }
    }

    /** Whether $now falls inside the campaign's active-hours window (if any). */
    private function withinWindow(array $campaign, \DateTimeImmutable $now): bool
    {
        $start = trim((string) ($campaign['window_start'] ?? ''));
        $end = trim((string) ($campaign['window_end'] ?? ''));
        if ($start === '' || $end === '') {
            return true; // no window = always
        }
        $tz = new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens');
        $cur = (int) $now->setTimezone($tz)->format('Hi');
        $s = (int) str_replace(':', '', substr($start, 0, 5));
        $e = (int) str_replace(':', '', substr($end, 0, 5));
        return $s <= $e ? ($cur >= $s && $cur <= $e) : ($cur >= $s || $cur <= $e); // handles overnight
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('message is required');
        }
        $target = ((string) ($data['target'] ?? 'public')) === 'dm' ? 'dm' : 'public';
        $fakeUserId = (int) ($data['fake_user_id'] ?? 0);

        return [
            'name'             => mb_substr($name, 0, 150),
            'message'          => mb_substr($message, 0, 2000),
            'target'           => $target,
            'fake_user_id'     => $fakeUserId > 0 ? $fakeUserId : null,
            'interval_minutes' => max(1, min((int) ($data['interval_minutes'] ?? 60), 10080)),
            'window_start'     => $this->normTime($data['window_start'] ?? null),
            'window_end'       => $this->normTime($data['window_end'] ?? null),
            'cooldown_hours'   => max(0, min((int) ($data['cooldown_hours'] ?? 24), 8760)),
            'max_recipients'   => max(1, min((int) ($data['max_recipients'] ?? 20), 1000)),
            'is_active'        => !array_key_exists('is_active', $data)
                || in_array(strtolower((string) $data['is_active']), ['1', 'true', 'on', 'yes'], true)
                || $data['is_active'] === true,
        ];
    }

    private function normTime(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^\d{1,2}:\d{2}/', $v)) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $v));
        return sprintf('%02d:%02d:00', max(0, min(23, $h)), max(0, min(59, $m)));
    }
}
