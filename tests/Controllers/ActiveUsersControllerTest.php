<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\ActiveUsersController;

/**
 * Golden-contract test for the migrated GET /api/active-users endpoint.
 *
 * The controller replaced public/api/active-users.php and must keep the exact
 * response contract the legacy file had: a 200 JSON body with success=true, an
 * integer count, a users array, and count === number of users.
 */
class ActiveUsersControllerTest extends TestCase
{
    public function testReturnsTheSamePayloadShapeAsTheLegacyEndpoint(): void
    {
        $response = (new ActiveUsersController())->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertIsInt($body['count']);
        $this->assertIsArray($body['users']);
        $this->assertCount($body['count'], $body['users'], 'count must equal the number of users');
    }
}
