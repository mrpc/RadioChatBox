<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\ProfileController;
use Pramnos\Database\Database;
use RadioChatBox\Services\PhotoService;
use RadioChatBox\Services\UserService;

/**
 * Golden-contract tests for the migrated "Profile" endpoints (replaced
 * public/api/update-profile.php and public/api/upload-photo.php).
 *
 * Both endpoints mutate real data on their success paths (upsert a profile row /
 * store an uploaded photo), so only the deterministic validation branches are
 * asserted here — these are pure input checks that run before any DB/service
 * call and therefore cause no side effects.
 */
class ProfileControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST  = [];
        $_GET   = [];
        $_FILES = [];
    }

    /**
     * update-profile: an empty body (no username/sessionId) must short-circuit
     * with 400 {success:false, error:"Username and session ID are required"}
     * before touching the database.
     */
    public function testUpdateProfileMissingCredentialsReturns400(): void
    {
        $_POST = [];

        $response = (new ProfileController())->update();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Username and session ID are required', $body['error']);
    }

    /**
     * update-profile: an out-of-range age must return 400 with the exact
     * "Age must be between 18 and 120" message (validation runs before any DB
     * work).
     */
    public function testUpdateProfileInvalidAgeReturns400(): void
    {
        $_POST = ['username' => 'u', 'sessionId' => 's', 'age' => 5];

        $response = (new ProfileController())->update();

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Age must be between 18 and 120', $body['error']);
    }

    /**
     * update-profile: an unrecognised sex value must return 400 with
     * "Invalid sex value".
     */
    public function testUpdateProfileInvalidSexReturns400(): void
    {
        $_POST = ['username' => 'u', 'sessionId' => 's', 'sex' => 'other'];

        $response = (new ProfileController())->update();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid sex value', json_decode($response->getBody(), true)['error']);
    }

    /**
     * upload-photo: missing username/recipient must return 400
     * {error:"Username and recipient are required"} before any upload happens.
     */
    public function testUploadPhotoMissingFieldsReturns400(): void
    {
        $_POST  = [];
        $_FILES = [];

        $response = (new ProfileController())->uploadPhoto();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Username and recipient are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * upload-photo: with the required fields present but no "photo" file in
     * $_FILES, the endpoint must return 400 {error:"No photo file provided"}.
     */
    public function testUploadPhotoMissingFileReturns400(): void
    {
        $_POST  = ['username' => 'u', 'recipient' => 'r'];
        $_FILES = [];

        $response = (new ProfileController())->uploadPhoto();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No photo file provided', json_decode($response->getBody(), true)['error']);
    }

    // ---------------------------------------------------------------------
    // DB-path coverage for the Phase-7 conversion (getPDO -> getDb): the
    // session-ownership check, the display-name conflict guards and the profile
    // upsert now run through the framework query builder / preparedQuery, so
    // drive update() against the real dev DB to pin that behaviour.
    // ---------------------------------------------------------------------

    /**
     * update-profile: when no session row matches (username, session_id) the
     * converted exists() ownership check must reject with 403 "Invalid session"
     * — the guard that runs before any profile write.
     */
    public function testUpdateProfileUnknownSessionReturns403(): void
    {
        $_POST = [
            'username'  => 'ghost_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'sessionId' => 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'age'       => 30,
        ];

        $response = (new ProfileController())->update();

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Invalid session', $body['error']);
    }

    /**
     * update-profile: a valid session with age/sex/location (and no displayName
     * key, so the display-name branch is skipped) must upsert the user_profiles
     * row via the converted EXCLUDED preparedQuery and return the success shape.
     * Running twice exercises the ON CONFLICT DO UPDATE branch. Seeded rows are
     * cleaned up.
     */
    public function testUpdateProfilePersistsProfileViaUpsert(): void
    {
        $pdo       = TestDatabase::connection();
        $username  = 'profctl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->seedSession($pdo, $username, $sessionId, null);

        try {
            $_POST = [
                'username'  => $username,
                'sessionId' => $sessionId,
                'age'       => 42,
                'sex'       => 'male',
                'location'  => 'Athens',
            ];
            $first = (new ProfileController())->update();
            $this->assertSame(200, $first->getStatusCode());
            $this->assertTrue(json_decode($first->getBody(), true)['success']);

            // Second update on the same (username, session_id) => DO UPDATE branch.
            $_POST['location'] = 'Thessaloniki';
            $second = (new ProfileController())->update();
            $this->assertSame(200, $second->getStatusCode());

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS n, MAX(location) AS loc FROM user_profiles
                 WHERE username = :u AND session_id = :s'
            );
            $stmt->execute(['u' => $username, 's' => $sessionId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame(1, (int) $row['n'], 'the upsert keeps exactly one profile row');
            $this->assertSame('Thessaloniki', $row['loc'], 'the second update overwrote via EXCLUDED');
        } finally {
            $pdo->prepare('DELETE FROM user_profiles WHERE username = :u')->execute(['u' => $username]);
            $pdo->prepare('DELETE FROM sessions WHERE username = :u')->execute(['u' => $username]);
        }
    }

    /**
     * update-profile: an authenticated user (session carries user_id) may not set
     * a display name equal to an existing username. This drives the converted
     * ->first() user_id fetch AND the first exists() conflict guard, returning
     * 400 "already taken as a username". Uses the user's own username as the
     * colliding value so a single seeded user suffices; all rows cleaned up.
     */
    public function testUpdateProfileDisplayNameCollidingWithUsernameReturns400(): void
    {
        $pdo      = TestDatabase::connection();
        $username = 'profconf_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $created  = (new UserService())->createUser($username, 'testpass123', 'simple_user', null, null);
        $this->assertTrue($created['success'] ?? false, 'seed user must be created');
        $userId    = $created['user']['id'];
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->seedSession($pdo, $username, $sessionId, $userId);

        try {
            $_POST = [
                'username'    => $username,
                'sessionId'   => $sessionId,
                'displayName' => $username, // collides with an existing username
                'age'         => 30,
            ];
            $response = (new ProfileController())->update();

            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame(
                'This display name is already taken as a username',
                json_decode($response->getBody(), true)['error']
            );
        } finally {
            $pdo->prepare('DELETE FROM user_profiles WHERE username = :u')->execute(['u' => $username]);
            $pdo->prepare('DELETE FROM sessions WHERE username = :u')->execute(['u' => $username]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }

    /**
     * update-profile success path: an authenticated user sets a unique display
     * name — it passes every uniqueness guard and is written to the users row (the
     * cache-clear + broadcast side of the branch also runs).
     */
    public function testUpdateProfileSetsDisplayNameSuccessfully(): void
    {
        $pdo      = TestDatabase::connection();
        $username = 'profok_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $created  = (new UserService())->createUser($username, 'testpass123', 'simple_user', null, null);
        $userId   = (int) $created['user']['id'];
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->seedSession($pdo, $username, $sessionId, $userId);
        $displayName = 'Disp' . substr($username, 7);

        try {
            $_POST = ['username' => $username, 'sessionId' => $sessionId, 'displayName' => $displayName, 'age' => 28];
            $response = (new ProfileController())->update();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(json_decode($response->getBody(), true)['success']);

            $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $this->assertSame($displayName, $stmt->fetchColumn(), 'the display name must be persisted');
        } finally {
            $pdo->prepare('DELETE FROM user_profiles WHERE username = ?')->execute([$username]);
            $pdo->prepare('DELETE FROM sessions WHERE username = ?')->execute([$username]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    /**
     * update-profile enforces display-name uniqueness against the three remaining
     * namespaces, each with its exact message: another user's display_name, a fake
     * user's nickname, and an active guest's nickname.
     */
    public function testUpdateProfileDisplayNameConflictsAcrossNamespaces(): void
    {
        $pdo      = TestDatabase::connection();
        $suffix   = substr(bin2hex(random_bytes(4)), 0, 8);
        $username = 'profc_' . $suffix;
        $created  = (new UserService())->createUser($username, 'testpass123', 'simple_user', null, null);
        $userId   = (int) $created['user']['id'];
        $sessionId = 'sess_' . $suffix;
        $this->seedSession($pdo, $username, $sessionId, $userId);

        // Another user already holding a display_name.
        $otherName = 'other_' . $suffix;
        $other     = (new UserService())->createUser($otherName, 'testpass123', 'simple_user', null, null);
        $otherId   = (int) $other['user']['id'];
        $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute(['Taken' . $suffix, $otherId]);

        // A fake user and an active guest nickname.
        $pdo->prepare('INSERT INTO fake_users (nickname, is_active) VALUES (?, TRUE)')->execute(['Fake' . $suffix]);
        $this->seedSession($pdo, 'Guest' . $suffix, 'gsess_' . $suffix, null);

        $cases = [
            ['Taken' . $suffix, 'This display name is already taken'],
            ['Fake'  . $suffix, 'This display name conflicts with a system user'],
            ['Guest' . $suffix, 'This display name is currently in use as a nickname'],
        ];

        try {
            foreach ($cases as [$name, $message]) {
                $_POST = ['username' => $username, 'sessionId' => $sessionId, 'displayName' => $name, 'age' => 30];
                $response = (new ProfileController())->update();
                $this->assertSame(400, $response->getStatusCode(), "conflict '{$name}' must be 400");
                $this->assertSame($message, json_decode($response->getBody(), true)['error']);
            }
        } finally {
            $pdo->prepare('DELETE FROM sessions WHERE username IN (?, ?)')->execute([$username, 'Guest' . $suffix]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute(['Fake' . $suffix]);
            $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$userId, $otherId]);
        }
    }

    /**
     * Seed a session row for the profile tests (user_id nullable for guests).
     */
    private function seedSession(\PDO $pdo, string $username, string $sessionId, ?int $userId): void
    {
        $pdo->prepare(
            'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:u, :s, :ip, :uid, NOW(), NOW())'
        )->execute([
            'u'   => $username,
            's'   => $sessionId,
            'ip'  => '127.0.0.1',
            'uid' => $userId,
        ]);
    }

    /**
     * upload-photo happy path: with valid fields and a real image file it stores
     * the photo via the (injected) PhotoService and returns
     * {success:true, attachment:{…}}. A PhotoService double accepts the CLI temp
     * file (is_uploaded_file is false under PHPUnit). The stored file + DB row are
     * cleaned up.
     */
    public function testUploadPhotoStoresViaInjectedService(): void
    {
        $im = imagecreatetruecolor(150, 150);
        imagefilledrectangle($im, 0, 0, 150, 150, imagecolorallocate($im, 40, 160, 90));
        $tmp = tempnam(sys_get_temp_dir(), 'pcup') . '.jpg';
        imagejpeg($im, $tmp);
        imagedestroy($im);

        $photoService = new class extends PhotoService {
            protected function isUploadedFile(string $path): bool
            {
                return is_file($path);
            }
        };

        $uploader = 'pcup_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $_POST  = ['username' => $uploader, 'recipient' => 'peer_' . $uploader];
        $_FILES = ['photo' => [
            'tmp_name' => $tmp,
            'name'     => 'pic.jpg',
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'image/jpeg',
        ]];

        $attachmentId = null;
        try {
            $response = (new ProfileController($photoService))->uploadPhoto();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertArrayHasKey('attachment_id', $body['attachment']);
            $attachmentId = $body['attachment']['attachment_id'];

            $disk = dirname(__DIR__, 2) . '/public' . $body['attachment']['file_path'];
            $this->assertFileExists($disk);
        } finally {
            @unlink($tmp);
            if ($attachmentId !== null) {
                $pdo  = TestDatabase::connection();
                $stmt = $pdo->prepare('SELECT file_path FROM attachments WHERE attachment_id = ?');
                $stmt->execute([$attachmentId]);
                $fp = (string) $stmt->fetchColumn();
                if ($fp !== '') {
                    $disk = dirname(__DIR__, 2) . '/public' . $fp;
                    if (is_file($disk)) {
                        @unlink($disk);
                    }
                }
                $pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$attachmentId]);
            }
        }
    }
}
