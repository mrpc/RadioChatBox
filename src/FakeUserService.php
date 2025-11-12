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
            SELECT id, nickname, age, sex, location, is_active, created_at
            FROM fake_users
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        
        // If minimum is 0 or disabled, deactivate all fake users
        if ($minUsers <= 0) {
            $this->deactivateAllFakeUsers();
            return;
        }

        // Calculate how many fake users we need
        $fakeUsersNeeded = max(0, $minUsers - $realUserCount);
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
