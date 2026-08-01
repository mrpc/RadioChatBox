<?php

namespace RadioChatBox\Tests\Auth;

use Pramnos\Framework\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Auth\RcbAuthDriver;
use RadioChatBox\Services\UserService;

/**
 * The framework auth seam for RadioChatBox accounts: RcbAuthDriver verifies a
 * PLAIN bcrypt password (RCB's scheme), so the framework Auth/LoginFlow can
 * authenticate existing RCB users without the peppered hash the default
 * DatabaseAuthDriver expects.
 */
class RcbAuthDriverTest extends TestCase
{
    private array $userIds = [];

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        foreach ($this->userIds as $uid) {
            try {
                $pdo->prepare('DELETE FROM users WHERE userid = ?')->execute([$uid]);
            } catch (\Throwable) {
            }
        }
        $this->userIds = [];
        parent::tearDown();
    }

    private function makeUser(string $username, string $password): int
    {
        $res = (new UserService())->createUser($username, $password, 'simple_user', 'e' . uniqid() . '@example.com', null);
        $this->assertTrue($res['success'] ?? false);
        $uid = (int) ($res['user']['userid'] ?? 0);
        $this->userIds[] = $uid;
        return $uid;
    }

    public function testVerifiesCorrectPlainBcryptPassword(): void
    {
        $username = 'driver_' . uniqid();
        $uid = $this->makeUser($username, 'secret-pass-1');

        $result = (new RcbAuthDriver())->verify($username, 'secret-pass-1');

        $this->assertTrue($result->success);
        $this->assertSame($uid, $result->uid);
        $this->assertSame($username, $result->username);
    }

    public function testRejectsWrongPassword(): void
    {
        $username = 'driver_' . uniqid();
        $this->makeUser($username, 'secret-pass-2');

        $result = (new RcbAuthDriver())->verify($username, 'nope');

        $this->assertFalse($result->success);
    }

    public function testRejectsUnknownUser(): void
    {
        $result = (new RcbAuthDriver())->verify('no-such-user-' . uniqid(), 'x');
        $this->assertFalse($result->success);
        $this->assertSame(404, $result->statusCode);
    }

    public function testEncryptedPasswordPathComparesHashesDirectly(): void
    {
        $username = 'driver_' . uniqid();
        $this->makeUser($username, 'secret-pass-3');

        // Fetch the stored hash and re-auth with it (encrypted=true).
        $hash = (string) TestDatabase::connection()
            ->query("SELECT password FROM users WHERE username = " . TestDatabase::connection()->quote($username))
            ->fetchColumn();

        $ok  = (new RcbAuthDriver())->verify($username, $hash, true);
        $bad = (new RcbAuthDriver())->verify($username, 'not-the-hash', true);

        $this->assertTrue($ok->success);
        $this->assertFalse($bad->success);
    }
}
