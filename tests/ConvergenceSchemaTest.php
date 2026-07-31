<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;

/**
 * Asserts the end state of the PF schema convergence on the freshly-migrated
 * test database (two-phase build in tests/bootstrap.php): users converged to the
 * framework shape, the messages/sessions names freed for the framework, settings
 * converged, and the legacy role enum dropped.
 */
class ConvergenceSchemaTest extends TestCase
{
    private static function pdo(): \PDO
    {
        return TestDatabase::connection();
    }

    private static function columns(string $table): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?"
        );
        $stmt->execute([$table]);
        $cols = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $cols[$r['column_name']] = $r['data_type'];
        }
        return $cols;
    }

    private static function tableExists(string $table): bool
    {
        $stmt = self::pdo()->prepare(
            "SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = ?"
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    /** users converged: userid bigint PK, usertype present, legacy role dropped. */
    public function testUsersConvergedToFrameworkShape(): void
    {
        $cols = self::columns('users');
        $this->assertArrayHasKey('userid', $cols);
        $this->assertSame('bigint', $cols['userid']);
        $this->assertArrayHasKey('usertype', $cols);
        $this->assertArrayHasKey('password', $cols);
        $this->assertArrayNotHasKey('id', $cols, 'legacy users.id must be gone');
        $this->assertArrayNotHasKey('role', $cols, 'legacy users.role must be dropped');
        // Framework companion columns exist.
        foreach (['regdate', 'lastlogin', 'active', 'validated'] as $c) {
            $this->assertArrayHasKey($c, $cols, "framework users.$c missing");
        }
    }

    /** Guest reserved at userid=1; the seeded admin was remapped off it. */
    public function testGuestReservedAndAdminRemapped(): void
    {
        $pdo = self::pdo();
        $guest = $pdo->query("SELECT username FROM users WHERE userid = 1")->fetchColumn();
        $this->assertSame('Guest', $guest);

        $admin = $pdo->query("SELECT userid, usertype FROM users WHERE username = 'admin'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($admin, 'seeded admin must exist');
        $this->assertGreaterThan(1, (int) $admin['userid'], 'admin must be remapped off userid 1');
        $this->assertSame(99, (int) $admin['usertype'], 'admin is root → usertype 99');
    }

    /** RCB tables renamed; framework tables created alongside. */
    public function testRenamedAndFrameworkTablesCoexist(): void
    {
        foreach (['chat_messages', 'presence_sessions'] as $t) {
            $this->assertTrue(self::tableExists($t), "RCB $t must exist");
        }
        foreach (['messages', 'sessions', 'users', 'usertokens', 'userdetails'] as $t) {
            $this->assertTrue(self::tableExists($t), "framework $t must exist");
        }
    }

    /** The 8 user FKs reference users(userid); the duplicate messages FK is gone. */
    public function testUserForeignKeysRepointed(): void
    {
        $pdo = self::pdo();
        // Every FK on these child columns must target users(userid).
        $sql = "SELECT tc.constraint_name, ccu.column_name AS ref_col
                FROM information_schema.table_constraints tc
                JOIN information_schema.constraint_column_usage ccu
                  ON tc.constraint_name = ccu.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND ccu.table_name = 'users'";
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($rows, 'constraint_name');
        $this->assertContains('fk_messages_user', $names);
        $this->assertContains('fk_user_activity_user', $names);
        $this->assertNotContains('messages_user_id_fkey', $names, 'duplicate messages FK must be dropped');
        foreach ($rows as $r) {
            $this->assertSame('userid', $r['ref_col'], "{$r['constraint_name']} must reference users(userid)");
        }
    }

    /** settings converged to setting/value/delete with the 64 seeded rows intact. */
    public function testSettingsConverged(): void
    {
        $cols = self::columns('settings');
        $this->assertArrayHasKey('setting', $cols);
        $this->assertArrayHasKey('value', $cols);
        $this->assertArrayHasKey('delete', $cols);
        $this->assertArrayNotHasKey('setting_key', $cols);
        // Rows exist (the baseline seeds ~64; other tests mutate settings during
        // the run, so assert presence, not an exact count) and a known seed key
        // is readable under the new column name.
        $count = (int) self::pdo()->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        $this->assertGreaterThan(0, $count);
        $probe = self::pdo()->query("SELECT value FROM settings WHERE setting = 'rate_limit_messages'")->fetchColumn();
        $this->assertNotFalse($probe, 'a seeded setting must be readable via the converged column');
    }
}
