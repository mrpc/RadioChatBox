<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\SettingsService;

/**
 * Covers SettingsService::updateFromAdmin(), the write path behind
 * public/api/admin/settings.php.
 *
 * Regression: the bot_* settings were silently dropped because they were
 * missing from the admin whitelist, while the endpoint still answered
 * "Settings updated successfully".
 */
class AdminSettingsUpdateTest extends TestCase
{
    /** Every bot setting the admin panel submits. */
    private const BOT_KEYS = [
        'bot_replies_enabled',
        'bot_llm_api_key',
        'bot_llm_base_url',
        'bot_llm_model',
        'bot_llm_temperature',
        'bot_llm_max_tokens',
        'bot_max_messages_per_thread',
        'bot_history_limit',
        'bot_farewell_prompt',
        'bot_farewell_messages',
        'bot_typing_seconds_per_word',
        'bot_typing_min_delay',
        'bot_typing_max_delay',
        'bot_read_delay_min',
        'bot_read_delay_max',
    ];

    private SettingsService $settings;
    private PDO $pdo;

    /** @var array<string,string|null> */
    private array $snapshot = [];

    protected function setUp(): void
    {
        $this->settings = new SettingsService();
        $this->pdo = Database::getPDO();

        // These tests write to the real settings table; remember what to restore.
        foreach (array_merge(self::BOT_KEYS, ['max_photo_size_mb', 'page_title']) as $key) {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $this->snapshot[$key] = $value === false ? null : (string) $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $key => $value) {
            if ($value === null) {
                $stmt = $this->pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
                $stmt->execute([$key]);
                continue;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
            );
            $stmt->execute([$key, $value]);
        }

        $this->settings->invalidateCache();
    }

    private function storedValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    // ------------------------------------------------------------------
    // Regression: bot settings must be persisted
    // ------------------------------------------------------------------

    public function testEveryBotSettingIsAdminEditable(): void
    {
        foreach (self::BOT_KEYS as $key) {
            $this->assertContains(
                $key,
                SettingsService::ADMIN_EDITABLE,
                "{$key} is submitted by the admin panel but would be dropped on save"
            );
        }
    }

    public function testBotSettingsArePersisted(): void
    {
        $payload = [
            'bot_replies_enabled' => 'true',
            'bot_llm_api_key' => 'sk-test-regression',
            'bot_llm_base_url' => 'https://api.deepseek.com',
            'bot_llm_model' => 'deepseek-chat',
            'bot_llm_temperature' => '1.3',
            'bot_llm_max_tokens' => '300',
            'bot_max_messages_per_thread' => '5',
            'bot_history_limit' => '20',
            'bot_farewell_prompt' => 'Κλείσε τη συζήτηση.',
            'bot_farewell_messages' => "τα λέμε\nfeugw",
            'bot_typing_seconds_per_word' => '1.5',
            'bot_typing_min_delay' => '2',
            'bot_typing_max_delay' => '45',
            'bot_read_delay_min' => '2',
            'bot_read_delay_max' => '8',
        ];

        $result = $this->settings->updateFromAdmin($payload);

        $this->assertSame([], $result['ignored']);
        $this->assertCount(count($payload), $result['saved']);

        foreach ($payload as $key => $expected) {
            $this->assertSame($expected, $this->storedValue($key), "{$key} was not saved");
        }
    }

    public function testSavedBotSettingsAreVisibleThroughTheCache(): void
    {
        // Prime the cache, then make sure a write busts it.
        $this->settings->get('bot_llm_model');

        $this->settings->updateFromAdmin(['bot_llm_model' => 'deepseek-reasoner']);

        $this->assertSame('deepseek-reasoner', $this->settings->get('bot_llm_model'));
    }

    // ------------------------------------------------------------------
    // Unknown keys
    // ------------------------------------------------------------------

    public function testUnknownKeysAreReportedInsteadOfSilentlyDropped(): void
    {
        $result = $this->settings->updateFromAdmin([
            'page_title' => 'Regression Title',
            'totally_made_up_key' => 'x',
            'another_bogus_key' => 'y',
        ]);

        $this->assertSame(['page_title'], $result['saved']);
        $this->assertSame(['totally_made_up_key', 'another_bogus_key'], $result['ignored']);
        $this->assertSame('Regression Title', $this->storedValue('page_title'));
        $this->assertNull($this->storedValue('totally_made_up_key'));
    }

    public function testEmptyPayloadIsANoop(): void
    {
        $result = $this->settings->updateFromAdmin([]);

        $this->assertSame(['saved' => [], 'ignored' => []], $result);
    }

    // ------------------------------------------------------------------
    // Numeric clamping
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function outOfRangeProvider(): array
    {
        return [
            'temperature above max' => ['bot_llm_temperature', '9', '2'],
            'temperature below min' => ['bot_llm_temperature', '-3', '0'],
            'tokens below min' => ['bot_llm_max_tokens', '1', '16'],
            'tokens above max' => ['bot_llm_max_tokens', '99999', '4000'],
            'message limit above max' => ['bot_max_messages_per_thread', '5000', '100'],
            'history below min' => ['bot_history_limit', '0', '2'],
            'typing speed above max' => ['bot_typing_seconds_per_word', '60', '10'],
            'negative read delay' => ['bot_read_delay_min', '-5', '0'],
            'max typing delay above cap' => ['bot_typing_max_delay', '10000', '600'],
        ];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testNumericSettingsAreClamped(string $key, string $sent, string $expected): void
    {
        $this->settings->updateFromAdmin([$key => $sent]);

        $this->assertSame($expected, $this->storedValue($key));
    }

    public function testInRangeNumbersAreStoredUnchanged(): void
    {
        $this->settings->updateFromAdmin([
            'bot_llm_temperature' => '0.7',
            'bot_max_messages_per_thread' => '6',
        ]);

        $this->assertSame('0.7', $this->storedValue('bot_llm_temperature'));
        $this->assertSame('6', $this->storedValue('bot_max_messages_per_thread'));
    }

    public function testWholeNumbersDoNotGainADecimalPart(): void
    {
        $this->settings->updateFromAdmin(['bot_typing_seconds_per_word' => '2.0']);

        $this->assertSame('2', $this->storedValue('bot_typing_seconds_per_word'));
    }

    public function testEmptyNumericValueClearsTheSetting(): void
    {
        // An empty field means "fall back to the default", not zero.
        $this->settings->updateFromAdmin(['bot_max_messages_per_thread' => '']);

        $this->assertSame('', $this->storedValue('bot_max_messages_per_thread'));
    }

    public function testTextSettingsAreNotClamped(): void
    {
        $text = "Σόρρυ, φεύγω\nexw douleia";

        $this->settings->updateFromAdmin(['bot_farewell_messages' => $text]);

        $this->assertSame($text, $this->storedValue('bot_farewell_messages'));
    }

    // ------------------------------------------------------------------
    // Photo size validation
    // ------------------------------------------------------------------

    public function testPhotoSizeWithinThePhpLimitIsAccepted(): void
    {
        $this->settings->updateFromAdmin(['max_photo_size_mb' => '5'], 50.0);

        $this->assertSame('5', $this->storedValue('max_photo_size_mb'));
    }

    public function testPhotoSizeAboveThePhpLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/upload_max_filesize/');

        $this->settings->updateFromAdmin(['max_photo_size_mb' => '500'], 50.0);
    }

    public function testPhotoSizeBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1MB/');

        $this->settings->updateFromAdmin(['max_photo_size_mb' => '0'], 50.0);
    }

    public function testPhotoSizeIsAcceptedWithoutAKnownPhpLimit(): void
    {
        $this->settings->updateFromAdmin(['max_photo_size_mb' => '12'], null);

        $this->assertSame('12', $this->storedValue('max_photo_size_mb'));
    }

    // ------------------------------------------------------------------
    // Transaction
    // ------------------------------------------------------------------

    public function testAFailedValueRollsBackTheWholeBatch(): void
    {
        $before = $this->storedValue('page_title');

        try {
            $this->settings->updateFromAdmin([
                'page_title' => 'Should be rolled back',
                'max_photo_size_mb' => '0', // invalid, throws after page_title was written
            ], 50.0);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame($before, $this->storedValue('page_title'));
        $this->assertFalse($this->pdo->inTransaction(), 'the transaction must not be left open');
    }
}
