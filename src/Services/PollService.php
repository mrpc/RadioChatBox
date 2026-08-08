<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Live chat polls: a moderator posts a multiple-choice question, users vote once
 * per session, everyone sees live results. Only one poll is "active" at a time
 * (creating a new one closes the previous), which keeps the front-end simple.
 */
class PollService
{
    private PramnosDatabase $db;

    public const MAX_OPTIONS = 6;
    public const MIN_OPTIONS = 2;

    /**
     * How long a finished poll keeps showing its result in the chat.
     *
     * Long enough that the people who voted see the outcome, short enough that
     * the card is not still sitting there over an unrelated conversation.
     */
    public const RESULTS_VISIBLE_MINUTES = 3;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Create a poll (and close any currently-active one). Returns the new id.
     *
     * @param list<string> $options
     * @throws \InvalidArgumentException on a bad question / option count.
     */
    public function create(string $question, array $options, ?string $createdBy = null, ?int $expiresMinutes = null): int
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('question is required');
        }
        $options = array_values(array_filter(array_map(
            static fn ($o): string => mb_substr(trim((string) $o), 0, 120),
            $options
        ), static fn (string $o): bool => $o !== ''));

        if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException('a poll needs between ' . self::MIN_OPTIONS . ' and ' . self::MAX_OPTIONS . ' options');
        }

        // Only one active poll at a time.
        $this->closeAllActive();

        $expiresAt = ($expiresMinutes !== null && $expiresMinutes > 0)
            ? (new \DateTimeImmutable("+{$expiresMinutes} minutes"))->format('Y-m-d H:i:s')
            : null;

        $result = $this->db->queryBuilder()->from('polls')->returning('id')->insert([
            'question'   => mb_substr($question, 0, 500),
            'options'    => json_encode($options, JSON_UNESCAPED_UNICODE),
            'created_by' => ($createdBy !== null && $createdBy !== '') ? mb_substr($createdBy, 0, 100) : null,
            'is_active'  => true,
            'expires_at' => $expiresAt,
        ]);

        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /** Cast (or change) a vote. Returns the fresh results including my_vote. */
    public function vote(int $pollId, string $session, ?string $username, int $optionIndex): array
    {
        $session = trim($session);
        if ($pollId <= 0 || $session === '') {
            throw new \InvalidArgumentException('poll and session are required');
        }
        $poll = $this->getPoll($pollId);
        if ($poll === null) {
            throw new \InvalidArgumentException('poll not found');
        }
        if (empty($poll['is_active']) || $this->isExpired($poll)) {
            throw new \RuntimeException('this poll is closed');
        }
        $optionCount = count($poll['options']);
        if ($optionIndex < 0 || $optionIndex >= $optionCount) {
            throw new \InvalidArgumentException('invalid option');
        }

        $qb = $this->db->queryBuilder()->from('poll_votes');
        $qb->upsert(
            [
                'poll_id'        => $pollId,
                'option_index'   => $optionIndex,
                'voter_session'  => $session,
                'voter_username' => ($username !== null && $username !== '') ? mb_substr($username, 0, 50) : null,
                'created_at'     => $qb->raw('NOW()'),
            ],
            ['poll_id', 'voter_session'],
            ['option_index', 'voter_username']
        );

        return $this->results($pollId, $session);
    }

    /**
     * Results for a poll: the question, options, per-option counts, total, and
     * (when a session is given) this voter's chosen option or null.
     *
     * @return array{id:int, question:string, options:list<string>, counts:list<int>,
     *   total:int, is_active:bool, my_vote:int|null}
     */
    public function results(int $pollId, ?string $session = null): array
    {
        $poll = $this->getPoll($pollId);
        if ($poll === null) {
            return ['id' => 0, 'question' => '', 'options' => [], 'counts' => [], 'total' => 0, 'is_active' => false, 'my_vote' => null];
        }

        $options = $poll['options'];
        $counts = array_fill(0, count($options), 0);

        $rows = $this->db->preparedQuery(
            'SELECT option_index, COUNT(*) AS c FROM poll_votes WHERE poll_id = :id GROUP BY option_index',
            ['id' => $pollId]
        );
        $total = 0;
        foreach (($rows ? $rows->fetchAll() : []) as $row) {
            $idx = (int) $row['option_index'];
            if ($idx >= 0 && $idx < count($counts)) {
                $counts[$idx] = (int) $row['c'];
                $total += (int) $row['c'];
            }
        }

        $myVote = null;
        if ($session !== null && trim($session) !== '') {
            $r = $this->db->preparedQuery(
                'SELECT option_index FROM poll_votes WHERE poll_id = :id AND voter_session = :s LIMIT 1',
                ['id' => $pollId, 's' => $session]
            );
            $v = $r ? $r->fetchColumn() : false;
            $myVote = $v === false ? null : (int) $v;
        }

        return [
            'id'        => (int) $poll['id'],
            'question'  => (string) $poll['question'],
            'options'   => $options,
            'counts'    => $counts,
            'total'     => $total,
            'is_active' => !empty($poll['is_active']) && !$this->isExpired($poll),
            'my_vote'   => $myVote,
            // Both are for the chat widget: the deadline so voters can see how
            // long they have, and the author so the person who started it can
            // close it without going to the admin panel.
            'expires_at' => $poll['expires_at'] ?? null,
            'created_by' => $poll['created_by'] ?? null,
        ];
    }

    /** The currently-active poll's results, or null when there is none. */
    public function activeResults(?string $session = null): ?array
    {
        $row = $this->db->preparedQuery(
            'SELECT id FROM polls
             WHERE is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC LIMIT 1'
        );
        $id = $row ? $row->fetchColumn() : false;
        if ($id !== false) {
            return $this->results((int) $id, $session);
        }

        // Nothing running — but a poll that has only just ended still has an
        // answer the room was waiting for. Closing it used to remove the card
        // instantly, so the people who voted never saw how it came out. Show the
        // final result for a short while, then let it go.
        //
        // Only if somebody voted: an empty poll has no outcome to report, and
        // the closing here is either an expiry nobody noticed or a mistake.
        $recent = $this->db->preparedQuery(
            'SELECT p.id FROM polls p
              WHERE p.is_active = FALSE
                AND p.closed_at IS NOT NULL
                AND p.closed_at > NOW() - INTERVAL \'' . self::RESULTS_VISIBLE_MINUTES . ' minutes\'
                AND EXISTS (SELECT 1 FROM poll_votes v WHERE v.poll_id = p.id)
              ORDER BY p.closed_at DESC LIMIT 1'
        );
        $recentId = $recent ? $recent->fetchColumn() : false;

        return $recentId === false ? null : $this->results((int) $recentId, $session);
    }

    /** Close a poll (id) or all active polls. Returns whether it applied. */
    public function close(int $pollId): bool
    {
        if ($pollId <= 0) {
            return false;
        }
        $qb = $this->db->queryBuilder()->from('polls');
        $qb->where('id', '=', $pollId)->update(['is_active' => false, 'closed_at' => $qb->raw('NOW()')]);
        return true;
    }

    /**
     * A page of polls (admin history), newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->queryBuilder()
            ->from('polls')
            ->orderBy('created_at', 'desc')
            ->limit(max(1, min($limit, 200)))
            ->offset(max(0, $offset))
            ->getAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->decodeOptions($row['options'] ?? null);
        }
        return $rows;
    }

    /**
     * Who voted for what on a poll (named voting, admin view). Returns the poll's
     * options plus, per option index, the list of voter usernames (falling back to
     * a short session tag when a vote was cast without a username).
     *
     * @return array{question:string, options:list<string>, voters:array<int, list<string>>}
     */
    public function voters(int $pollId): array
    {
        $poll = $this->getPoll($pollId);
        if ($poll === null) {
            return ['question' => '', 'options' => [], 'voters' => []];
        }
        $options = $poll['options'];
        $voters = array_fill(0, count($options), []);

        $rows = $this->db->preparedQuery(
            'SELECT option_index, voter_username, voter_session
             FROM poll_votes WHERE poll_id = :id ORDER BY created_at ASC',
            ['id' => $pollId]
        );
        foreach (($rows ? $rows->fetchAll() : []) as $row) {
            $idx = (int) $row['option_index'];
            if ($idx < 0 || $idx >= count($voters)) {
                continue;
            }
            $name = trim((string) ($row['voter_username'] ?? ''));
            if ($name === '') {
                $name = 'guest#' . substr((string) ($row['voter_session'] ?? ''), 0, 6);
            }
            $voters[$idx][] = $name;
        }

        return ['question' => (string) $poll['question'], 'options' => $options, 'voters' => $voters];
    }

    /** @return array<string,mixed>|null with `options` decoded to a list */
    private function getPoll(int $pollId): ?array
    {
        $rows = $this->db->queryBuilder()->from('polls')->where('id', '=', $pollId)->limit(1)->getAll();
        if ($rows === []) {
            return null;
        }
        $poll = $rows[0];
        $poll['options'] = $this->decodeOptions($poll['options'] ?? null);
        return $poll;
    }

    private function closeAllActive(): void
    {
        $qb = $this->db->queryBuilder()->from('polls');
        $qb->where('is_active', '=', true)->update(['is_active' => false, 'closed_at' => $qb->raw('NOW()')]);
    }

    private function isExpired(array $poll): bool
    {
        $expires = $poll['expires_at'] ?? null;
        if ($expires === null || $expires === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable((string) $expires) <= new \DateTimeImmutable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return list<string> */
    private function decodeOptions(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
