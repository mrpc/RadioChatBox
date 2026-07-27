<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\UserProfileController;

/**
 * Golden-contract test for the migrated GET /api/user-profile endpoint (replaced
 * public/api/user-profile.php). Must keep the {success, profile{age,sex,location,
 * display_name}} shape and the 400 response when username is missing.
 */
class UserProfileControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testReturnsProfileShape(): void
    {
        $_GET = ['username' => 'admin'];

        $response = (new UserProfileController())->show();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('age', $body['profile']);
        $this->assertArrayHasKey('sex', $body['profile']);
        $this->assertArrayHasKey('location', $body['profile']);
        $this->assertArrayHasKey('display_name', $body['profile']);
    }

    public function testMissingUsernameReturns400(): void
    {
        $_GET = [];

        $response = (new UserProfileController())->show();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
    }
}
