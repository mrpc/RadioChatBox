<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AuthController;
use RadioChatBox\Database;
use RadioChatBox\UserService;

/**
 * Golden-contract tests for the migrated Auth endpoints login / logout /
 * register (replaced public/api/{login,logout,register}.php).
 *
 * Each POST endpoint asserts the deterministic validation guards the legacy
 * files enforced (empty body -> 400, missing required fields -> 400) plus the
 * bad-credentials 401 for login. The success paths mutate real session/user
 * state (and register rebalances fake users), so they are exercised
 * live/by the frontend rather than asserted here.
 */
class AuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
    }

    /**
     * login: an empty request body reproduces the legacy "Invalid JSON" 400.
     */
    public function testLoginEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new AuthController())->login();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * login: a body missing password/sessionId trips the required-fields 400.
     */
    public function testLoginMissingFieldsReturns400(): void
    {
        $_POST = ['username' => 'someone']; // no password / sessionId
        $response = (new AuthController())->login();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Username, password, and session ID are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * login: credentials that cannot authenticate return the legacy 401
     * "Invalid username or password" without binding any session.
     */
    public function testLoginBadCredentialsReturns401(): void
    {
        $_POST = [
            'username' => 'nope_' . substr(md5(__METHOD__), 0, 12),
            'password' => 'definitely-not-a-real-password',
            'sessionId' => 'sess_' . substr(md5(__METHOD__), 0, 8),
        ];
        $response = (new AuthController())->login();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid username or password', json_decode($response->getBody(), true)['error']);
    }

    /**
     * login: valid credentials link the session to the user via the upsert that
     * was converted from raw PDO to the framework DB layer. Logging in twice on
     * the same (username, session_id) must exercise BOTH the INSERT and the
     * ON CONFLICT DO UPDATE branch, leaving exactly one session row carrying the
     * user_id — the golden behaviour the raw statement guaranteed. All seeded
     * rows are cleaned up.
     */
    public function testLoginSuccessLinksSessionToUserViaUpsert(): void
    {
        $pdo      = Database::getPDO();
        $username = 'authctl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $password = 'testpass123';
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $created = (new UserService())->createUser($username, $password, 'simple_user', null, null);
        $this->assertTrue($created['success'] ?? false, 'test user must be created');
        $userId = $created['user']['id'];

        try {
            $_POST = ['username' => $username, 'password' => $password, 'sessionId' => $sessionId];

            // First login inserts the session row.
            $first = (new AuthController())->login();
            $this->assertSame(200, $first->getStatusCode());
            $body = json_decode($first->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame($userId, $body['user']['id']);

            // Second login on the same (username, session_id) takes the ON CONFLICT
            // DO UPDATE branch — still one row, still linked.
            $second = (new AuthController())->login();
            $this->assertSame(200, $second->getStatusCode());

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS n, MAX(user_id) AS uid FROM sessions
                 WHERE username = :u AND session_id = :s'
            );
            $stmt->execute(['u' => $username, 's' => $sessionId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->assertSame(1, (int) $row['n'], 'the upsert must keep exactly one session row');
            $this->assertSame($userId, (int) $row['uid'], 'the session must be linked to the user');
        } finally {
            $pdo->prepare('DELETE FROM sessions WHERE username = :u')->execute(['u' => $username]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }

    /**
     * logout: an empty request body reproduces the legacy "Invalid JSON" 400.
     */
    public function testLogoutEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new AuthController())->logout();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * logout: a body without sessionId trips the "Session ID is required" 400.
     */
    public function testLogoutMissingSessionReturns400(): void
    {
        $_POST = ['foo' => 'bar']; // present body, but no sessionId
        $response = (new AuthController())->logout();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Session ID is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * logout: an unknown session is a no-op that still returns the success
     * contract {success, message} the frontend expects.
     */
    public function testLogoutUnknownSessionReturnsSuccessShape(): void
    {
        $_POST = ['sessionId' => 'ghost_' . substr(md5(__METHOD__), 0, 12)];
        $response = (new AuthController())->logout();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('User logged out successfully', $body['message']);
    }

    /**
     * register: an empty request body reproduces the legacy "Invalid JSON" 400.
     */
    public function testRegisterEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new AuthController())->register();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * register: a body missing username/sessionId trips the required-fields 400.
     */
    public function testRegisterMissingFieldsReturns400(): void
    {
        $_POST = ['username' => 'someone']; // no sessionId
        $response = (new AuthController())->register();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Username and session ID are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * register: an out-of-range age is rejected with the legacy 400. Location
     * and sex are supplied so the age check fires regardless of whether the
     * shared dev DB has require_profile on (require branch) or off (elseif
     * branch) — both paths reach the same age validation.
     */
    public function testRegisterInvalidAgeReturns400(): void
    {
        $_POST = [
            'username' => 'ageprobe_' . substr(md5(__METHOD__), 0, 8),
            'sessionId' => 'sess_' . substr(md5(__METHOD__), 0, 8),
            'age' => '5',
            'location' => 'Testville',
            'sex' => 'male',
        ];
        $response = (new AuthController())->register();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Age must be between 18 and 120', json_decode($response->getBody(), true)['error']);
    }
}
