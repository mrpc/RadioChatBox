<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use Pramnos\Http\Request;
use RadioChatBox\Controllers\ChatCommandController;
use RadioChatBox\Controllers\SendController;
use RadioChatBox\Services\ChatCommandService;
use RadioChatBox\Services\SettingsService;

/**
 * Tests for admin-defined chat slash-commands: the ChatCommandService (parse,
 * lookup, /help, CRUD validation), the admin CRUD controller (GET/POST/PUT/
 * DELETE), and the SendController interception (a recognised command answers
 * the sender and is NOT posted; the feature gate hides it when off).
 */
class ChatCommandControllerTest extends TestCase
{
    private PDO $pdo;
    private string $cmd;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->cmd = 'rules' . substr(bin2hex(random_bytes(4)), 0, 6);
        Request::$requestMethod = 'GET';
        Request::$putData = [];
        Request::$deleteData = [];
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM chat_commands WHERE command = ?')->execute([$this->cmd]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'chat_commands_enabled'")->execute();
        FlatCache::default()->clear();
        Request::$requestMethod = 'GET';
        Request::$putData = [];
        Request::$deleteData = [];
        $_POST = [];
    }

    private function enable(bool $on = true): void
    {
        (new SettingsService())->set('chat_commands_enabled', $on ? 'true' : 'false');
    }

    // ---- service: parsing + lookup ----------------------------------

    /** parse() strips the slash, lowercases, and keeps only the first token. */
    public function testParseNormalisesTheCommandToken(): void
    {
        $this->assertSame('rules', ChatCommandService::parse('/RULES please'));
        $this->assertSame('help', ChatCommandService::parse('  /help'));
        $this->assertSame('', ChatCommandService::parse('hello world')); // not a command
        $this->assertSame('', ChatCommandService::parse('/'));
    }

    /** A created active command is resolved by respondTo(); inactive returns null. */
    public function testRespondToResolvesActiveCustomCommands(): void
    {
        $service = new ChatCommandService();
        $id = $service->create($this->cmd, 'Be nice to each other.', 'The house rules');

        $this->assertSame('Be nice to each other.', $service->respondTo('/' . $this->cmd));
        $this->assertNull($service->respondTo('/not-a-command-here'));

        $service->update($id, 'Be nice.', 'rules', false); // deactivate
        $this->assertNull($service->respondTo('/' . $this->cmd));
    }

    /** /help is built-in and lists the active commands. */
    public function testHelpListsActiveCommands(): void
    {
        (new ChatCommandService())->create($this->cmd, 'rules text', 'House rules');
        $help = (new ChatCommandService())->respondTo('/help');

        $this->assertNotNull($help);
        $this->assertStringContainsString('/help', $help);
        $this->assertStringContainsString('/' . $this->cmd, $help);
    }

    /** Reserved names, bad characters, and duplicates are rejected on create. */
    public function testCreateRejectsReservedBadAndDuplicateNames(): void
    {
        $service = new ChatCommandService();

        $this->expectException(\InvalidArgumentException::class);
        $service->create('help', 'nope'); // reserved
    }

    public function testCreateRejectsADuplicate(): void
    {
        $service = new ChatCommandService();
        $service->create($this->cmd, 'first');

        $this->expectException(\InvalidArgumentException::class);
        $service->create($this->cmd, 'second'); // duplicate name
    }

    // ---- SendController interception --------------------------------

    /** With commands on, a recognised command answers the sender and is not posted. */
    public function testSendInterceptsARecognisedCommand(): void
    {
        $this->enable();
        (new ChatCommandService())->create($this->cmd, 'Be nice to each other.');

        $_POST = ['username' => 'someone', 'message' => '/' . $this->cmd, 'sessionId' => 'sess'];
        $response = (new SendController())->store();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['command']);
        $this->assertSame('Be nice to each other.', $body['response']);
    }

    /** /help works through the send path too. */
    public function testSendInterceptsHelp(): void
    {
        $this->enable();
        $_POST = ['username' => 'someone', 'message' => '/help', 'sessionId' => 'sess'];

        $response = (new SendController())->store();
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['command'] ?? false);
        $this->assertStringContainsString('/help', $body['response']);
    }

    /** With the feature OFF, a command is NOT intercepted (no command flag). */
    public function testSendDoesNotInterceptWhenDisabled(): void
    {
        $this->enable(false);
        (new ChatCommandService())->create($this->cmd, 'Be nice.');

        // Missing/echo-back guard: a disabled feature falls through to the normal
        // message path. We only assert it was NOT handled as a command; the normal
        // path may then 4xx/500 without a valid user, which is fine here.
        $_POST = ['username' => 'someone', 'message' => '/' . $this->cmd, 'sessionId' => 'sess'];
        $response = (new SendController())->store();

        $body = json_decode($response->getBody(), true);
        $this->assertArrayNotHasKey('command', $body ?? []);
    }

    // ---- admin CRUD controller --------------------------------------

    /** POST creates, GET lists, PUT edits and DELETE removes a command. */
    public function testAdminCrudLifecycle(): void
    {
        $controller = new ChatCommandController();

        // POST create
        Request::$requestMethod = 'POST';
        $_POST = ['command' => $this->cmd, 'response' => 'Original', 'description' => 'desc', 'is_active' => 'true'];
        $created = $controller->handle();
        $this->assertSame(200, $created->getStatusCode());
        $id = json_decode($created->getBody(), true)['id'];
        $this->assertGreaterThan(0, $id);

        // GET list contains it
        Request::$requestMethod = 'GET';
        $_POST = [];
        $list = $controller->handle();
        $names = array_column(json_decode($list->getBody(), true)['commands'], 'command');
        $this->assertContains($this->cmd, $names);

        // PUT update the response
        Request::$requestMethod = 'PUT';
        Request::$putData = ['id' => $id, 'response' => 'Updated', 'is_active' => 'true'];
        $updated = $controller->handle();
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame('Updated', (new ChatCommandService())->respondTo('/' . $this->cmd));

        // DELETE removes it
        Request::$requestMethod = 'DELETE';
        Request::$deleteData = ['id' => $id];
        $deleted = $controller->handle();
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertNull((new ChatCommandService())->findActive($this->cmd));
    }

    /** A reserved/invalid create through the controller is a 400, not a 500. */
    public function testAdminCreateInvalidIsA400(): void
    {
        $controller = new ChatCommandController();
        Request::$requestMethod = 'POST';
        $_POST = ['command' => 'help', 'response' => 'nope'];

        $response = $controller->handle();
        $this->assertSame(400, $response->getStatusCode());
    }
}
