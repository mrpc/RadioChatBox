<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use RadioChatBox\Services\FakeUserService;

/**
 * Covers the per-bot overrides stored on a fake user: which provider and model it
 * uses, and which script it writes in.
 *
 * These are what let bots run on different LLMs side by side, so a bad value must
 * degrade to "use the global setting" rather than leave a bot pointing at an
 * endpoint that does not exist.
 */
class FakeUserBotSettingsTest extends TestCase
{
    private PDO $pdo;
    private FakeUserService $service;
    private int $fakeUserId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->service = new FakeUserService();

        $this->pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute(['BotOverrideTest']);
        $this->pdo->prepare(
            'INSERT INTO fake_users (nickname, age, sex, location, is_active) VALUES (?, 25, ?, ?, TRUE)'
        )->execute(['BotOverrideTest', 'female', 'Αθήνα']);

        $stmt = $this->pdo->prepare('SELECT id FROM fake_users WHERE nickname = ?');
        $stmt->execute(['BotOverrideTest']);
        $this->fakeUserId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM fake_users WHERE id = ?')->execute([$this->fakeUserId]);
    }

    public function testAProviderAndModelAreStored(): void
    {
        $result = $this->service->updateBotSettings($this->fakeUserId, [
            'bot_enabled' => true,
            'bot_llm_provider' => 'openai',
            'bot_llm_model' => 'gpt-5.4-nano',
            'bot_reply_language' => 'greeklish',
        ]);

        $this->assertSame('openai', $result['bot_llm_provider']);
        $this->assertSame('gpt-5.4-nano', $result['bot_llm_model']);
        $this->assertSame('greeklish', $result['bot_reply_language']);
    }

    public function testAnUnknownProviderFallsBackToTheGlobalSetting(): void
    {
        // Storing it would leave this bot calling an endpoint that does not exist.
        $result = $this->service->updateBotSettings($this->fakeUserId, [
            'bot_llm_provider' => 'definitely-not-a-provider',
        ]);

        $this->assertNull($result['bot_llm_provider']);
    }

    public function testAnUnknownLanguageFallsBackToAuto(): void
    {
        // A CHECK constraint also guards this, and a violation would abort the whole
        // save rather than just ignoring one field.
        $result = $this->service->updateBotSettings($this->fakeUserId, [
            'bot_reply_language' => 'klingon',
        ]);

        $this->assertNull($result['bot_reply_language']);
    }

    public function testClearingAnOverrideRestoresTheGlobalSetting(): void
    {
        $this->service->updateBotSettings($this->fakeUserId, [
            'bot_llm_provider' => 'openai',
            'bot_llm_model' => 'gpt-5.4-nano',
            'bot_reply_language' => 'greek',
        ]);

        $result = $this->service->updateBotSettings($this->fakeUserId, [
            'bot_llm_provider' => '',
            'bot_llm_model' => '',
            'bot_reply_language' => '',
        ]);

        $this->assertNull($result['bot_llm_provider']);
        $this->assertNull($result['bot_llm_model']);
        $this->assertNull($result['bot_reply_language']);
    }

    public function testTheOverridesAreReadBackWithTheFakeUser(): void
    {
        $this->service->updateBotSettings($this->fakeUserId, [
            'bot_enabled' => true,
            'bot_llm_provider' => 'openai',
            'bot_llm_model' => 'gpt-5.4-mini',
            'bot_reply_language' => 'greeklish',
        ]);

        // The admin panel and BotService both read the user back, so the columns have
        // to be in the select lists too.
        $fakeUser = $this->service->getFakeUserById($this->fakeUserId);

        $this->assertSame('openai', $fakeUser['bot_llm_provider']);
        $this->assertSame('gpt-5.4-mini', $fakeUser['bot_llm_model']);
        $this->assertSame('greeklish', $fakeUser['bot_reply_language']);
    }
}
