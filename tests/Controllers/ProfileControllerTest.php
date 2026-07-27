<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\ProfileController;

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
}
