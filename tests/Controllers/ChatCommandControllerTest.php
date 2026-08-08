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

    /** /help answers through the send path with custom commands switched off. */
    public function testSendAnswersHelpEvenWithCustomCommandsOff(): void
    {
        (new SettingsService())->set('chat_commands_enabled', 'false');
        $_POST = ['username' => 'someone', 'message' => '/help', 'sessionId' => 'sess'];

        $body = json_decode((new SendController())->store()->getBody(), true);

        $this->assertTrue($body['command'] ?? false, '/help must answer, not post as a message');
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

    /**
     * GET /api/commands lists what a user can type, and never the responses.
     *
     * The autocomplete needs names and descriptions; the response body is the
     * payoff for typing the command and has no business being public.
     */
    public function testPublicListOffersNamesWithoutResponses(): void
    {
        (new SettingsService())->set('chat_commands_enabled', 'true');
        (new ChatCommandService())->create($this->cmd, 'the secret reply body', 'House rules');

        $body = json_decode((new ChatCommandController())->index()->getBody(), true);

        $this->assertTrue($body['success']);
        $names = array_column($body['commands'], 'command');
        $this->assertContains('help', $names, '/help is always available');
        $this->assertContains($this->cmd, $names);

        $encoded = json_encode($body);
        $this->assertStringNotContainsString('the secret reply body', $encoded);
        foreach ($body['commands'] as $command) {
            $this->assertSame(['command', 'description'], array_keys($command));
        }
    }

    /**
     * /help answers even on a station with no custom commands.
     *
     * It used to be gated behind chat_commands_enabled together with them, so
     * the one command that tells you what you can type did nothing at all.
     */
    public function testHelpWorksWithCustomCommandsDisabled(): void
    {
        (new SettingsService())->set('chat_commands_enabled', 'false');

        $text = (new \RadioChatBox\Services\CommandCatalog())
            ->helpTextFor(\RadioChatBox\Services\Authz::SIMPLE_USER);

        $this->assertStringContainsString('/help', $text);
        $this->assertStringContainsString('/me', $text);
    }

    /** The listing is what the caller can run — staff commands only for staff. */
    public function testHelpListsModeratorCommandsOnlyForStaff(): void
    {
        $catalog = new \RadioChatBox\Services\CommandCatalog();

        $listener = $catalog->helpTextFor(\RadioChatBox\Services\Authz::SIMPLE_USER);
        $this->assertStringNotContainsString('/mute', $listener);
        $this->assertStringNotContainsString('/ban', $listener);

        $staff = $catalog->helpTextFor(\RadioChatBox\Services\Authz::MODERATOR);
        $this->assertStringContainsString('/mute', $staff);
        $this->assertStringContainsString('/ban', $staff);
    }

    /** Commands the station has switched off are not suggested. */
    public function testPublicListOmitsCustomCommandsWhenTheFeatureIsOff(): void
    {
        (new SettingsService())->set('chat_commands_enabled', 'false');
        (new ChatCommandService())->create($this->cmd, 'reply', 'House rules');

        $body = json_decode((new ChatCommandController())->index()->getBody(), true);

        $this->assertNotContains($this->cmd, array_column($body['commands'], 'command'));
        $this->assertContains('help', array_column($body['commands'], 'command'));
    }
}
