<?php

namespace RadioChatBox;

use PDO;
use Redis;

/**
 * Manages fake users that appear in the chat to make it look more active
 */
class FakeUserService
{
    private PDO $pdo;
    private Redis $redis;
    private string $redisPrefix;

    public function __construct()
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->redisPrefix = Database::getRedisPrefix();
    }

    /**
     * Get all fake users
     */
    public function getAllFakeUsers(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nickname, age, sex, location, is_active, created_at,
                   bot_enabled, bot_persona, bot_custom_prompt, bot_max_messages,
                   bot_typing_seconds_per_word, bot_farewell_messages,
                   bot_llm_provider, bot_llm_model, bot_reply_language, bot_ignore_chance
            FROM fake_users
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single fake user by nickname
     */
    public function getFakeUserByNickname(string $nickname): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nickname, age, sex, location, is_active, created_at,
                   bot_enabled, bot_persona, bot_custom_prompt, bot_max_messages,
                   bot_typing_seconds_per_word, bot_farewell_messages,
                   bot_llm_provider, bot_llm_model, bot_reply_language, bot_ignore_chance
            FROM fake_users
            WHERE nickname = ?
        ");
        $stmt->execute([$nickname]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Update the bot (LLM auto-reply) configuration of a fake user.
     *
     * Only the keys present in $options are changed. Recognised keys:
     * bot_enabled (bool), bot_persona, bot_custom_prompt, bot_farewell_messages
     * (string|null), bot_max_messages (int|null), bot_typing_seconds_per_word
     * (float|null).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>|null The updated row, or null if not found
     */
    public function updateBotSettings(int $id, array $options): ?array
    {
        $columns = [
            'bot_enabled' => PDO::PARAM_BOOL,
            'bot_persona' => PDO::PARAM_STR,
            'bot_custom_prompt' => PDO::PARAM_STR,
            'bot_farewell_messages' => PDO::PARAM_STR,
            'bot_max_messages' => PDO::PARAM_INT,
            'bot_ignore_chance' => PDO::PARAM_INT,
            'bot_typing_seconds_per_word' => PDO::PARAM_STR,
            // Per-bot overrides, so bots can run on different LLMs side by side
            'bot_llm_provider' => PDO::PARAM_STR,
            'bot_llm_model' => PDO::PARAM_STR,
            'bot_reply_language' => PDO::PARAM_STR,
        ];

        $sets = [];
        $values = [];

        foreach ($columns as $column => $type) {
            if (!array_key_exists($column, $options)) {
                continue;
            }

            $value = $options[$column];

            if ($column === 'bot_enabled') {
                $value = (bool) $value;
            } elseif ($value === '' || $value === null) {
                // Empty means "fall back to the global setting".
                $value = null;
                $type = PDO::PARAM_NULL;
            } elseif ($column === 'bot_max_messages' || $column === 'bot_ignore_chance') {
                $value = max(0, min(100, (int) $value));
            } elseif ($column === 'bot_typing_seconds_per_word') {
                $value = (string) max(0, min(10, (float) $value));
            } elseif ($column === 'bot_llm_provider') {
                // An unknown provider would leave this bot without an endpoint;
                // treat it as "use the global setting".
                if (!LlmProviders::isKnown((string) $value)) {
                    $value = null;
                    $type = PDO::PARAM_NULL;
                }
            } elseif ($column === 'bot_reply_language') {
                if (!isset(BotService::LANGUAGES[(string) $value])) {
                    $value = null;
                    $type = PDO::PARAM_NULL;
                }
            }

            $sets[] = "{$column} = :{$column}";
            $values[$column] = [$value, $type];
        }

        if (empty($sets)) {
            return $this->getFakeUserById($id);
        }

        $stmt = $this->pdo->prepare("
            UPDATE fake_users
            SET " . implode(', ', $sets) . "
            WHERE id = :id
            RETURNING id, nickname, age, sex, location, is_active, created_at,
                      bot_enabled, bot_persona, bot_custom_prompt, bot_max_messages,
                      bot_typing_seconds_per_word, bot_farewell_messages,
                      bot_llm_provider, bot_llm_model, bot_reply_language, bot_ignore_chance
        ");

        foreach ($values as $column => [$value, $type]) {
            $stmt->bindValue(":{$column}", $value, $type);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Get a single fake user by id
     */
    public function getFakeUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nickname, age, sex, location, is_active, created_at,
                   bot_enabled, bot_persona, bot_custom_prompt, bot_max_messages,
                   bot_typing_seconds_per_word, bot_farewell_messages,
                   bot_llm_provider, bot_llm_model, bot_reply_language, bot_ignore_chance
            FROM fake_users
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Get currently active fake users
     */
    public function getActiveFakeUsers(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nickname, age, sex, location
            FROM fake_users
            WHERE is_active = TRUE
            ORDER BY nickname
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a fake user's profile (nickname, age, sex, location).
     *
     * Only keys present in $fields are changed. Renaming is allowed but is not a
     * simple column update: the nickname IS the identity used in
     * private_messages, dm_blocks and the Redis active-user list, so those are
     * rewritten in the same transaction - otherwise existing conversations would
     * be orphaned.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>|null The updated row, or null if not found
     *
     * @throws \InvalidArgumentException on invalid input or a taken nickname
     */
    public function updateFakeUser(int $id, array $fields): ?array
    {
        $current = $this->getFakeUserById($id);

        if ($current === null) {
            return null;
        }

        $newNickname = null;

        if (array_key_exists('nickname', $fields)) {
            $candidate = trim((string) $fields['nickname']);

            if ($candidate === '') {
                throw new \InvalidArgumentException('Nickname is required');
            }
            if (mb_strlen($candidate) < 3 || mb_strlen($candidate) > 50) {
                throw new \InvalidArgumentException('Nickname must be between 3 and 50 characters');
            }

            if ($candidate !== $current['nickname']) {
                if ($this->nicknameTaken($candidate, $id)) {
                    throw new \InvalidArgumentException('Nickname already exists');
                }
                $newNickname = $candidate;
            }
        }

        $age = array_key_exists('age', $fields) ? $fields['age'] : $current['age'];
        if ($age === '' || $age === null) {
            $age = null;
        } else {
            $age = (int) $age;
            if ($age < 18 || $age > 99) {
                throw new \InvalidArgumentException('Age must be between 18 and 99');
            }
        }

        $sex = array_key_exists('sex', $fields) ? $fields['sex'] : $current['sex'];
        $sex = ($sex === '' || $sex === null) ? null : (string) $sex;
        if ($sex !== null && !in_array($sex, ['male', 'female', 'other'], true)) {
            throw new \InvalidArgumentException('Sex must be male, female or other');
        }

        $location = array_key_exists('location', $fields) ? $fields['location'] : $current['location'];
        $location = ($location === '' || $location === null) ? null : (string) $location;

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE fake_users
                SET nickname = :nickname, age = :age, sex = :sex, location = :location
                WHERE id = :id
                RETURNING id, nickname, age, sex, location, is_active, created_at,
                          bot_enabled, bot_persona, bot_custom_prompt, bot_max_messages,
                          bot_typing_seconds_per_word, bot_farewell_messages,
                      bot_llm_provider, bot_llm_model, bot_reply_language, bot_ignore_chance
            ");
            $stmt->execute([
                'nickname' => $newNickname ?? $current['nickname'],
                'age' => $age,
                'sex' => $sex,
                'location' => $location,
                'id' => $id,
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($newNickname !== null) {
                $this->renameInConversations((string) $current['nickname'], $newNickname);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            // Unique violation on nickname (race with another admin).
            if ($e instanceof \PDOException && $e->getCode() === '23505') {
                throw new \InvalidArgumentException('Nickname already exists');
            }

            throw $e;
        }

        // Keep the visible user list in sync with the new profile.
        if ($updated !== false && $updated['is_active']) {
            if ($newNickname !== null) {
                $this->removeFakeUserFromRedis((string) $current['nickname']);
            }
            $this->addFakeUserToRedis($updated);
        }

        return $updated === false ? null : $updated;
    }

    /**
     * Whether a nickname is already used by another fake user or a real account.
     */
    private function nicknameTaken(string $nickname, int $excludeFakeUserId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM fake_users WHERE LOWER(nickname) = LOWER(?) AND id <> ?
            UNION ALL
            SELECT 1 FROM users WHERE LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$nickname, $excludeFakeUserId, $nickname]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Carry a renamed fake user's identity across its existing conversations.
     * Runs inside the caller's transaction.
     */
    private function renameInConversations(string $oldNickname, string $newNickname): void
    {
        $oldSessionId = 'fake_' . md5($oldNickname);
        $newSessionId = 'fake_' . md5($newNickname);

        $stmt = $this->pdo->prepare("
            UPDATE private_messages
            SET from_username = :new,
                from_session_id = CASE WHEN from_session_id = :old_session THEN :new_session ELSE from_session_id END
            WHERE from_username = :old
        ");
        $stmt->execute([
            'new' => $newNickname,
            'old' => $oldNickname,
            'old_session' => $oldSessionId,
            'new_session' => $newSessionId,
        ]);

        $stmt = $this->pdo->prepare("
            UPDATE private_messages
            SET to_username = :new,
                to_session_id = CASE WHEN to_session_id = :old_session THEN :new_session ELSE to_session_id END
            WHERE to_username = :old
        ");
        $stmt->execute([
            'new' => $newNickname,
            'old' => $oldNickname,
            'old_session' => $oldSessionId,
            'new_session' => $newSessionId,
        ]);

        // DM blocks are keyed by username too.
        $stmt = $this->pdo->prepare('UPDATE dm_blocks SET blocker_username = ? WHERE blocker_username = ?');
        $stmt->execute([$newNickname, $oldNickname]);

        $stmt = $this->pdo->prepare('UPDATE dm_blocks SET blocked_username = ? WHERE blocked_username = ?');
        $stmt->execute([$newNickname, $oldNickname]);
    }

    /**
     * Add a new fake user
     */
    public function addFakeUser(string $nickname, ?int $age = null, ?string $sex = null, ?string $location = null): array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO fake_users (nickname, age, sex, location)
            VALUES (:nickname, :age, :sex, :location)
            RETURNING id, nickname, age, sex, location, is_active, created_at
        ");
        
        $stmt->execute([
            'nickname' => $nickname,
            'age' => $age,
            'sex' => $sex,
            'location' => $location
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a fake user
     */
    public function deleteFakeUser(int $id): bool
    {
        // First deactivate if active
        $this->setFakeUserActive($id, false);

        $stmt = $this->pdo->prepare("DELETE FROM fake_users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle fake user active status
     */
    public function toggleFakeUser(int $id): array
    {
        $stmt = $this->pdo->prepare("
            UPDATE fake_users
            SET is_active = NOT is_active
            WHERE id = :id
            RETURNING id, nickname, age, sex, location, is_active
        ");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Update Redis active users list
        if ($user) {
            if ($user['is_active']) {
                $this->addFakeUserToRedis($user);
            } else {
                $this->removeFakeUserFromRedis($user['nickname']);
            }
        }

        return $user;
    }

    /**
     * Set a specific fake user's active status
     */
    public function setFakeUserActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE fake_users
            SET is_active = :active
            WHERE id = :id
            RETURNING nickname, age, sex, location, is_active
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($active) {
                $this->addFakeUserToRedis($user);
            } else {
                $this->removeFakeUserFromRedis($user['nickname']);
            }
            return true;
        }
        return false;
    }

    /**
     * Balance fake users to meet minimum user count
     * Activates or deactivates fake users as needed
     */
    public function balanceFakeUsers(int $realUserCount): void
    {
        $minUsers = (int) (new SettingsService())->get('minimum_users', 0);
        
        // Try to get radio listener count first (preferred target)
        $targetUserCount = null;
        $radioListeners = null;
        try {
            $radioService = new \RadioChatBox\RadioStatusService();
            $nowPlaying = $radioService->getNowPlaying();
            
            if (isset($nowPlaying['listeners']) && $nowPlaying['listeners'] !== null && $nowPlaying['listeners'] > 0) {
                $radioListeners = $nowPlaying['listeners'];
                $targetUserCount = $radioListeners; // Use radio listeners as target
            }
        } catch (\Exception $e) {
            // If radio service fails, fall back to minimum_users
            error_log("Failed to get radio listeners for fake user balancing: " . $e->getMessage());
        }
        
        // If radio listeners not available, fall back to minimum_users setting.
        if ($targetUserCount === null) {
            if ($minUsers <= 0) {
                $this->deactivateAllFakeUsers();
                return;
            }
            // No live radio number → use the admin-configured minimum with a
            // ±10% jitter so the count drifts naturally instead of sitting on a
            // constant value when there is no real traffic.
            $targetUserCount = $this->getJitteredTarget($minUsers);
        }

        // Calculate how many fake users we need to reach target
        $fakeUsersNeeded = max(0, $targetUserCount - $realUserCount);
        $currentActiveFake = $this->countActiveFakeUsers();

        if ($fakeUsersNeeded === $currentActiveFake) {
            return; // Already balanced
        }

        if ($fakeUsersNeeded > $currentActiveFake) {
            // Need to activate more fake users
            $toActivate = $fakeUsersNeeded - $currentActiveFake;
            $this->activateRandomFakeUsers($toActivate);
        } else {
            // Need to deactivate some fake users
            $toDeactivate = $currentActiveFake - $fakeUsersNeeded;
            $this->deactivateRandomFakeUsers($toDeactivate);
        }
    }

    /**
     * Compute a target based on the configured minimum with a ±10% jitter.
     *
     * The result is cached in Redis for a few minutes so it stays stable across
     * the frequent balance calls (every heartbeat); otherwise the visible user
     * count would flicker constantly. A new random value is picked when the
     * cache expires or when the admin changes the base minimum.
     */
    private function getJitteredTarget(int $minUsers): int
    {
        $cacheKey = $this->redisPrefix . 'fake_users:jitter_target';

        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== false) {
                [$base, $target] = array_map('intval', explode(':', $cached) + [0, 0]);
                if ($base === $minUsers) {
                    return $target;
                }
            }
        } catch (\Exception $e) {
            // Fall through and recompute.
        }

        $delta = (int) floor($minUsers * 0.10);
        $min = max(0, $minUsers - $delta);
        $max = $minUsers + $delta;

        try {
            $target = ($max > $min) ? random_int($min, $max) : $minUsers;
        } catch (\Exception $e) {
            $target = $minUsers;
        }

        try {
            // Refresh roughly every 3 minutes for a natural drift.
            $this->redis->setex($cacheKey, 180, $minUsers . ':' . $target);
        } catch (\Exception $e) {
            // Non-fatal: without caching it just recomputes next call.
        }

        return $target;
    }

    /**
     * Count currently active fake users
     */
    private function countActiveFakeUsers(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM fake_users WHERE is_active = TRUE");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Activate random inactive fake users
     */
    private function activateRandomFakeUsers(int $count): void
    {
        if ($count <= 0) return;

        $stmt = $this->pdo->prepare("
            SELECT id, nickname, age, sex, location
            FROM fake_users
            WHERE is_active = FALSE
            ORDER BY RANDOM()
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $count, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            $this->setFakeUserActive($user['id'], true);
        }
    }

    /**
     * Deactivate random active fake users
     */
    private function deactivateRandomFakeUsers(int $count): void
    {
        if ($count <= 0) return;

        $stmt = $this->pdo->prepare("
            SELECT id, nickname
            FROM fake_users
            WHERE is_active = TRUE
            ORDER BY RANDOM()
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $count, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as $user) {
            $this->setFakeUserActive($user['id'], false);
        }
    }

    /**
     * Deactivate all fake users
     */
    private function deactivateAllFakeUsers(): void
    {
        // Get all active fake users first
        $stmt = $this->pdo->query("SELECT nickname FROM fake_users WHERE is_active = TRUE");
        $nicknames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Deactivate in database
        $this->pdo->exec("UPDATE fake_users SET is_active = FALSE WHERE is_active = TRUE");

        // Remove from Redis
        foreach ($nicknames as $nickname) {
            $this->removeFakeUserFromRedis($nickname);
        }
    }

    /**
     * Add fake user to Redis active users
     */
    private function addFakeUserToRedis(array $user): void
    {
        $userData = json_encode([
            'nickname' => $user['nickname'],
            'age' => $user['age'],
            'sex' => $user['sex'],
            'location' => $user['location'],
            'is_fake' => true
        ]);

        $this->redis->hSet(
            $this->redisPrefix . 'active_users',
            $user['nickname'],
            $userData
        );
    }

    /**
     * Remove fake user from Redis active users
     */
    private function removeFakeUserFromRedis(string $nickname): void
    {
        $this->redis->hDel($this->redisPrefix . 'active_users', $nickname);
    }
}
