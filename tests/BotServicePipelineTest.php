<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\BlockService;
use RadioChatBox\Services\BotService;
use Pramnos\Database\Database;
use RadioChatBox\JobQueue;
use RadioChatBox\Services\LlmService;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Tests\Support\CapturesAppLog;

/**
 * Covers the stateful half of BotService: the job pipeline, the conversation
 * budget, the admin takeover and delivery.
 *
 * PostgreSQL and Redis are real (the container has both); only the HTTP call is
 * stubbed, through the injected LlmService. The queue runs in its own namespace
 * so a live worker's jobs are never claimed or flushed by the tests.
 */
class BotServicePipelineTest extends TestCase
{
    use CapturesAppLog;

    private PDO $pdo;
    private JobQueue $queue;
    private StubSettings $settings;
    private StubLlm $llm;
    private BotService $bot;

    private string $nick;
    private string $peer;
    private string $peerSession;
    private int $fakeUserId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();

        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->nick = 'bot_' . $suffix;
        $this->peer = 'peer_' . $suffix;
        $this->peerSession = 'sess_' . $suffix;

        $stmt = $this->pdo->prepare(
            'INSERT INTO fake_users (nickname, age, sex, location, is_active, bot_enabled)
             VALUES (?, 27, ?, ?, TRUE, TRUE) RETURNING id'
        );
        $stmt->execute([$this->nick, 'female', 'GR']);
        $this->fakeUserId = (int) $stmt->fetchColumn();

        $this->queue = new JobQueue('jobs:phpunit:' . $suffix);
        $this->settings = new StubSettings();
        $this->settings->values = [
            'bot_replies_enabled' => 'true',
            'bot_max_messages_per_thread' => '4',
            'bot_history_limit' => '20',
            'bot_typing_seconds_per_word' => '1.5',
            'bot_typing_min_delay' => '2',
            'bot_typing_max_delay' => '45',
            'bot_read_delay_min' => '0',
            'bot_read_delay_max' => '0',
            // 100% chance of replying: whether a bot ignores a new conversation is a
            // dice roll, and a dice roll must not decide whether the suite passes.
            // The tests that are about ignoring set their own chance per bot.
            'bot_ignore_chance' => '0', // 0% ignore == always reply
            'bot_insult_block_threshold' => '3',
            // Deterministic: no immediate block unless a test asks for it, so the
            // strike-threshold tests are not decided by a dice roll.
            'bot_immediate_block_chance' => '0',
            // Deterministic emoji: keep the (common) ones so replies are not
            // altered by a dice roll. Emoji-stripping is tested explicitly.
            'bot_emoji_chance' => '100',
        ];
        $this->llm = new StubLlm();

        $this->bot = new BotService($this->settings, $this->queue, $this->llm);
    }

    protected function tearDown(): void
    {
        $this->queue->flush();

        $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ? OR to_username = ?')
            ->execute([$this->nick, $this->nick]);
        // bot_threads rows go with the fake user (ON DELETE CASCADE).
        $this->pdo->prepare('DELETE FROM fake_users WHERE id = ?')->execute([$this->fakeUserId]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->peer]);
        // Blocks outlive the fake user row, so clear them explicitly.
        $this->pdo->prepare('DELETE FROM dm_blocks WHERE blocker_username = ? OR blocked_username = ?')
            ->execute([$this->nick, $this->peer]);

        $this->verifyAndStopAppLogCapture();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Store a message from the peer to the bot, as private-message.php would. */
    private function incoming(string $text): void
    {
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$this->peer, $this->peerSession, $this->nick, 'fake_' . md5($this->nick), $text]);
    }

    /** Store a message the bot sent. */
    private function botSaid(string $text): void
    {
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$this->nick, 'fake_' . md5($this->nick), $this->peer, $this->peerSession, $text]);
    }

    /**
     * @return list<array{id:string,type:string,payload:array<string,mixed>,attempts:int,run_at:int}>
     */
    private function claimAll(): array
    {
        // Jobs are scheduled in the future; claimDue() only returns due ones, so
        // pull them by rewriting their score to now.
        $redis = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        $key = \Pramnos\Redis\ConnectionManager::getInstance()->prefix() . $this->queue->getNamespace() . ':delayed';
        foreach ($redis->zRange($key, 0, -1) as $jobId) {
            $redis->zAdd($key, time() - 1, $jobId);
        }

        return $this->queue->claimDue(50);
    }

    private function threadRow(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bot_threads WHERE fake_user_id = ? AND peer_username = ?'
        );
        $stmt->execute([$this->fakeUserId, $this->peer]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function setBotColumn(string $column, mixed $value): void
    {
        $stmt = $this->pdo->prepare("UPDATE fake_users SET {$column} = :value WHERE id = :id");
        // Booleans must not go through the default string binding: PostgreSQL
        // rejects '' for a boolean column.
        $stmt->bindValue(':value', $value, is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->fakeUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** A live thread for this bot with another peer, replied to just now. */
    private function insertActiveThread(string $peer): void
    {
        $this->pdo->prepare(
            'INSERT INTO bot_threads (fake_user_id, peer_username, messages_sent, last_reply_at)
             VALUES (?, ?, 1, NOW())'
        )->execute([$this->fakeUserId, $peer]);
    }

    /** This bot's row from listThreads(), or null if it is not listed. */
    private function threadInList(): ?array
    {
        foreach ($this->bot->listThreads() as $thread) {
            if ($thread['nickname'] === $this->nick && $thread['peer_username'] === $this->peer) {
                return $thread;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Model list (regression: deepseek-chat was rejected by the API)
    // ------------------------------------------------------------------

    public function testAvailableModelsAreOfferedWithLabels(): void
    {
        $models = BotService::availableModels();

        $this->assertNotEmpty($models);
        foreach ($models as $id => $label) {
            $this->assertIsString($id);
            $this->assertNotSame('', trim($label), "{$id} has no label for the dropdown");
        }
    }

    public function testTheRetiredModelNameIsNoLongerOffered(): void
    {
        // 'deepseek-chat' made every reply fail with HTTP 400.
        $this->assertArrayNotHasKey('deepseek-chat', BotService::availableModels());
        $this->assertFalse(BotService::isKnownModel('deepseek-chat'));
    }

    public function testDefaultModelIsOneOfTheAvailableOnes(): void
    {
        $this->assertArrayHasKey(BotService::defaultModel(), BotService::availableModels());
        $this->assertTrue(BotService::isKnownModel(BotService::defaultModel()));
    }

    public function testLlmServiceFallsBackToTheDefaultModel(): void
    {
        $this->assertSame(BotService::defaultModel(), (new LlmService(['model' => '']))->getModel());
    }

    // ------------------------------------------------------------------
    // onIncomingMessage
    // ------------------------------------------------------------------

    public function testIncomingMessageSchedulesAReply(): void
    {
        $this->incoming('geia');

        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));

        $jobs = $this->claimAll();
        $this->assertCount(1, $jobs);
        $this->assertSame(BotService::JOB_REPLY, $jobs[0]['type']);
        $this->assertSame($this->fakeUserId, $jobs[0]['payload']['fake_user_id']);
        $this->assertSame($this->peer, $jobs[0]['payload']['peer_username']);
        $this->assertSame($this->peerSession, $jobs[0]['payload']['peer_session_id']);
        $this->assertGreaterThan(0, $jobs[0]['payload']['epoch']);
    }

    public function testNothingIsScheduledWhenRepliesAreDisabledGlobally(): void
    {
        $this->settings->values['bot_replies_enabled'] = 'false';

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertSame(0, $this->queue->size());
    }

    public function testNothingIsScheduledWhenTheBotIsDisabledForThatUser(): void
    {
        $this->setBotColumn('bot_enabled', false);

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertSame(0, $this->queue->size());
    }

    public function testWillAutoReplyNeedsBotEnabledAndGlobalReplies(): void
    {
        // Drives the "skip the admin DM notification, the bot has it" decision.
        $this->settings->values['bot_replies_enabled'] = 'true';
        $this->assertTrue($this->bot->willAutoReply(['bot_enabled' => true]));
        $this->assertFalse($this->bot->willAutoReply(['bot_enabled' => false]));

        $this->settings->values['bot_replies_enabled'] = 'false';
        $this->assertFalse($this->bot->willAutoReply(['bot_enabled' => true]));
    }

    public function testIgnoreModifierAccessorsFallBackToDefaults(): void
    {
        $this->assertSame(BotService::DEFAULT_IGNORE_CHANCE_PER_ACTIVE_CHAT, $this->bot->ignoreChancePerActiveChat());
        $this->assertSame(BotService::DEFAULT_RETURNING_PEER_REPLY_BOOST, $this->bot->returningPeerReplyBoost());
    }

    public function testEffectiveIgnoreRisesWithActiveChatsAndFallsForAKnownPeer(): void
    {
        $this->settings->values['bot_ignore_chance'] = '10';
        $this->settings->values['bot_ignore_chance_per_active_chat'] = '5';
        $this->settings->values['bot_returning_peer_reply_boost'] = '80';

        $fakeUser = ['id' => $this->fakeUserId, 'nickname' => $this->nick, 'bot_ignore_chance' => null];

        // Stranger, no other chats: just the base chance.
        $this->assertSame(10, $this->bot->effectiveIgnoreChance($fakeUser, $this->peer));

        // Two other live conversations: +5 each.
        $this->insertActiveThread('peerA_' . $this->peer);
        $this->insertActiveThread('peerB_' . $this->peer);
        $this->assertSame(20, $this->bot->effectiveIgnoreChance($fakeUser, $this->peer));

        // The peer is now someone the bot has answered before: -80, clamped at 0.
        $this->botSaid('geia');
        $this->assertSame(0, $this->bot->effectiveIgnoreChance($fakeUser, $this->peer));
    }

    public function testConversationProviderHonoursThePerBotOverride(): void
    {
        // A per-bot provider wins even when the global setting is "both".
        $this->settings->values['bot_llm_provider'] = 'both';
        $this->assertSame(
            'openai',
            $this->bot->conversationProvider(['id' => 1, 'bot_llm_provider' => 'openai'], 'alice')
        );
    }

    public function testConversationProviderFallsThroughForAConcreteGlobal(): void
    {
        // Not "both" and no override: empty means "use the global setting".
        $this->settings->values['bot_llm_provider'] = 'deepseek';
        $this->assertSame('', $this->bot->conversationProvider(['id' => 1], 'alice'));
    }

    public function testBothPicksOneProviderStablyPerConversation(): void
    {
        $this->settings->values['bot_llm_provider'] = 'both';
        $providers = array_keys(\RadioChatBox\Services\LlmProviders::PROVIDERS);

        // Same bot+peer always resolves to the same provider (no mid-chat flip).
        $chosen = $this->bot->conversationProvider(['id' => 7], 'nikos');
        $this->assertContains($chosen, $providers);
        $this->assertSame($chosen, $this->bot->conversationProvider(['id' => 7], 'nikos'));

        // Across many conversations the choice spreads over every provider.
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $seen[$this->bot->conversationProvider(['id' => $i], 'peer' . $i)] = true;
        }
        $this->assertCount(count($providers), $seen);
    }

    public function testListThreadsReportsWhetherTheFakeUserIsActive(): void
    {
        $this->incoming('geia');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        // Active by default, so the conversation is a healthy "replying" one.
        $this->assertTrue($this->threadInList()['is_active']);

        // Deactivated (e.g. rotated out): the panel must show it cannot reply.
        $this->setBotColumn('is_active', false);
        $this->assertFalse($this->threadInList()['is_active']);
    }

    public function testGetThreadStateReportsWhetherTheFakeUserIsActive(): void
    {
        $this->incoming('geia');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        $this->assertTrue($this->bot->getThreadState($this->nick, $this->peer)['is_active']);

        $this->setBotColumn('is_active', false);
        $this->assertFalse($this->bot->getThreadState($this->nick, $this->peer)['is_active']);
    }

    /**
     * Regression: an attachment-only DM (a shared photo, no caption) showed
     * "[attachment]" in the Bot Activity thread because threadMessages() returned
     * the raw attachment_id instead of a resolved `attachment` object. It now
     * reuses ChatService::attachPhotoData(), so the bubble can render the photo.
     */
    public function testThreadMessagesResolveAttachmentPhotos(): void
    {
        $attId = 'att_' . substr(bin2hex(random_bytes(6)), 0, 12);
        $path  = '/uploads/photos/' . $attId . '.jpg';
        $this->pdo->prepare(
            'INSERT INTO attachments
                (attachment_id, filename, original_filename, file_path, file_size,
                 mime_type, width, height, uploaded_by, ip_address, is_deleted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)'
        )->execute([
            $attId, $attId . '.jpg', 'holiday.jpg', $path, 12345,
            'image/jpeg', 800, 600, $this->peer, '203.0.113.7',
        ]);

        // The peer sends a photo with no caption (message left empty).
        $this->pdo->prepare(
            'INSERT INTO private_messages
                (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$this->peer, $this->peerSession, $this->nick, 'fake_' . md5($this->nick), '', $attId]);

        try {
            $messages = $this->bot->threadMessages($this->nick, $this->peer);

            $this->assertCount(1, $messages);
            $msg = $messages[0];
            // The raw column is dropped in favour of the resolved object.
            $this->assertArrayNotHasKey('attachment_id', $msg);
            $this->assertIsArray($msg['attachment']);
            $this->assertSame($path, $msg['attachment']['file_path']);
            $this->assertSame($attId . '.jpg', $msg['attachment']['filename']);
        } finally {
            $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$attId]);
        }
    }

    /**
     * A bot always replies to STAFF (moderator/admin/root) — no ignore roll —
     * so it can be tested by DMing it from an admin account. Here the ignore
     * chance is forced to 100%, which would silence a normal user.
     */
    public function testBotAlwaysRepliesToStaffDespiteIgnore(): void
    {
        $staff = 'admin_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare('INSERT INTO users (username, password, usertype) VALUES (?, ?, 90)')
            ->execute([$staff, 'x']);
        $this->settings->values['bot_ignore_chance'] = '100'; // a normal user would be ignored

        try {
            $scheduled = $this->bot->onIncomingMessage($this->nick, $staff, 'sess_' . $staff, 'geia');
            $this->assertTrue($scheduled, 'the bot must reply to staff even at 100% ignore');
        } finally {
            $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ? OR to_username = ?')->execute([$staff, $staff]);
            $this->pdo->prepare('DELETE FROM bot_threads WHERE peer_username = ?')->execute([$staff]);
            $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$staff]);
        }
    }

    /** An admin steering directive is stored on the thread and cleared with ''. */
    public function testThreadDirectiveSetAndClear(): void
    {
        $this->assertTrue($this->bot->setThreadDirective($this->nick, $this->peer, 'steer towards X'));
        $this->assertSame('steer towards X', $this->bot->getThreadDirective($this->nick, $this->peer));

        $this->bot->setThreadDirective($this->nick, $this->peer, '');
        $this->assertSame('', $this->bot->getThreadDirective($this->nick, $this->peer));
    }

    public function testNothingIsScheduledForAnInactiveFakeUser(): void
    {
        $this->setBotColumn('is_active', false);

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
    }

    public function testNothingIsScheduledForAnUnknownRecipient(): void
    {
        $this->assertFalse($this->bot->onIncomingMessage('no_such_bot_xyz', $this->peer, $this->peerSession));
    }

    public function testBotsDoNotReplyToOtherBots(): void
    {
        // A second fake user as the sender: replying would loop forever.
        $otherNick = 'bot_other_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, TRUE)')
            ->execute([$otherNick]);

        try {
            $this->assertFalse($this->bot->onIncomingMessage($this->nick, $otherNick, 'fake_' . md5($otherNick)));
            $this->assertSame(0, $this->queue->size());
        } finally {
            $this->pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$otherNick]);
        }
    }

    public function testNothingIsScheduledOnceTheAdminTookOver(): void
    {
        $this->bot->takeOverThread($this->nick, $this->peer, 'admin');

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertSame(0, $this->queue->size());
    }

    public function testNothingIsScheduledAfterTheConversationEnded(): void
    {
        $this->pdo->prepare(
            'INSERT INTO bot_threads (fake_user_id, peer_username, farewell_sent_at)
             VALUES (?, ?, NOW())'
        )->execute([$this->fakeUserId, $this->peer]);

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
    }

    public function testANewerMessageSupersedesAQueuedReply(): void
    {
        $this->incoming('first');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);
        $this->incoming('second');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        $jobs = $this->claimAll();
        $this->assertCount(2, $jobs);

        usort($jobs, fn ($a, $b) => $a['payload']['epoch'] <=> $b['payload']['epoch']);

        // The older job must be dropped rather than produce a second reply.
        $this->assertStringContainsString('superseded', $this->bot->processReplyJob($jobs[0]['payload']));
        $this->assertStringContainsString('queued reply', $this->bot->processReplyJob($jobs[1]['payload']));
    }

    // ------------------------------------------------------------------
    // processReplyJob
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function replyPayload(int $epoch = 1): array
    {
        return [
            'fake_user_id' => $this->fakeUserId,
            'fake_nickname' => $this->nick,
            'peer_username' => $this->peer,
            'peer_session_id' => $this->peerSession,
            'epoch' => $epoch,
        ];
    }

    private function currentEpoch(): int
    {
        $key = \Pramnos\Redis\ConnectionManager::getInstance()->prefix() . 'bot:epoch:' . $this->fakeUserId . ':' . md5($this->peer);
        $value = \Pramnos\Redis\ConnectionManager::getInstance()->connection()->get($key);

        return $value === false ? 0 : (int) $value;
    }

    public function testReplyJobWithAMalformedPayloadIsSkipped(): void
    {
        $this->assertStringContainsString('malformed', $this->bot->processReplyJob([]));
    }

    public function testReplyJobAsksTheLlmAndQueuesDelivery(): void
    {
        $this->incoming('ti kaneis;');
        $this->llm->reply = 'kala esy;';

        $result = $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('queued reply', $result);
        $this->assertCount(1, $this->llm->calls);

        // The prompt is built from the bot's own profile.
        $this->assertStringContainsString($this->nick, $this->llm->calls[0]['system']);
        $this->assertStringContainsString('27 ετών', $this->llm->calls[0]['system']);

        // The user's message is the last user turn the model sees (trailing
        // system notes about time/staleness may follow it).
        $messages = $this->llm->calls[0]['messages'];
        $userTurns = array_values(array_filter($messages, fn ($m) => $m['role'] === 'user'));
        $this->assertSame('ti kaneis;', end($userTurns)['content']);

        $jobs = $this->claimAll();
        $this->assertSame(BotService::JOB_DELIVER, $jobs[0]['type']);
        $this->assertSame('kala esy;', $jobs[0]['payload']['message']);
        $this->assertFalse($jobs[0]['payload']['is_farewell']);
    }

    public function testTypingDelayScalesWithTheReplyLength(): void
    {
        $this->incoming('pes mou kati');
        $this->llm->reply = 'ena dyo tria tessera pente';  // 5 words * 1.5 = 7.5 -> 8

        $this->bot->processReplyJob($this->replyPayload(0));

        $jobs = $this->claimAll();
        $this->assertGreaterThanOrEqual(7, $jobs[0]['run_at'] - time() + 1);
    }

    public function testReplyIsSkippedWhenTheLlmIsNotConfigured(): void
    {
        $this->incoming('geia');
        $this->llm->configured = false;

        $this->assertStringContainsString('not configured', $this->bot->processReplyJob($this->replyPayload(0)));
        $this->assertStringContainsString('not configured', (string) $this->threadRow()['last_error']);
    }

    public function testReplyIsSkippedWithoutConversationHistory(): void
    {
        // No message from the peer: there is nothing to answer.
        $this->assertStringContainsString('no conversation history', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    public function testAFailedLlmCallIsRetriedAndRecorded(): void
    {
        $this->incoming('geia');
        $this->llm->throw = new \RuntimeException('HTTP 400: bad model');

        try {
            $this->bot->processReplyJob($this->replyPayload(0));
            $this->fail('a failed reply must bubble up so the worker retries it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('LLM call failed', $e->getMessage());
        }

        $this->assertStringContainsString('bad model', (string) $this->threadRow()['last_error']);
    }

    public function testAnEmptyReplyIsSkipped(): void
    {
        $this->incoming('geia');
        $this->llm->reply = '   ';

        $this->assertStringContainsString('empty reply', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    public function testReplyIsSkippedWhenTheAdminTookOverMeanwhile(): void
    {
        $this->incoming('geia');
        $this->bot->takeOverThread($this->nick, $this->peer, 'admin');

        $this->assertStringContainsString('taken over', $this->bot->processReplyJob($this->replyPayload(0)));
        $this->assertSame([], $this->llm->calls, 'no API call may be made for a taken-over thread');
    }

    public function testReplyIsSkippedWhenRepliesGetDisabledMeanwhile(): void
    {
        $this->incoming('geia');
        $this->settings->values['bot_replies_enabled'] = 'false';

        $this->assertStringContainsString('disabled globally', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    public function testReplyIsSkippedWhenTheBotGetsDisabledMeanwhile(): void
    {
        $this->incoming('geia');
        $this->setBotColumn('bot_enabled', false);

        $this->assertStringContainsString('inactive or bot disabled', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    // ------------------------------------------------------------------
    // Conversation limit / closing message
    // ------------------------------------------------------------------

    private function exhaustBudget(int $sent = 4): void
    {
        $this->pdo->prepare(
            'INSERT INTO bot_threads (fake_user_id, peer_username, messages_sent)
             VALUES (?, ?, ?)
             ON CONFLICT (fake_user_id, peer_username) DO UPDATE SET messages_sent = EXCLUDED.messages_sent'
        )->execute([$this->fakeUserId, $this->peer, $sent]);
    }

    public function testTheLastMessageIsWrittenByTheLlmWithAClosingInstruction(): void
    {
        $this->incoming('kai meta;');
        $this->exhaustBudget();
        $this->llm->reply = 'me zitane, ta leme!';

        $result = $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('limit reached', $result);
        $this->assertStringContainsString('closing message', $result);
        $this->assertStringContainsString('ΤΕΛΕΥΤΑΙΟ', $this->llm->calls[0]['system']);

        $jobs = $this->claimAll();
        $this->assertTrue($jobs[0]['payload']['is_farewell']);
        $this->assertSame('me zitane, ta leme!', $jobs[0]['payload']['message']);
    }

    public function testAFailedClosingCallFallsBackToACannedGoodbye(): void
    {
        $this->incoming('και μετά;');
        $this->exhaustBudget();
        $this->llm->throw = new \RuntimeException('network down');

        // A goodbye is not worth retrying: the conversation still has to close.
        $result = $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('limit reached', $result);
        $jobs = $this->claimAll();
        $this->assertTrue($jobs[0]['payload']['is_farewell']);
        $this->assertContains(
            $jobs[0]['payload']['message'],
            BotService::splitVariants(BotService::DEFAULT_FAREWELLS)
        );
    }

    public function testTheClosingMessageUsesThePerBotVariantsWhenTheCallFails(): void
    {
        $this->incoming('kai meta;');
        $this->exhaustBudget();
        $this->setBotColumn('bot_farewell_messages', "only one variant here");
        $this->llm->configured = false;

        $this->bot->processReplyJob($this->replyPayload(0));

        $jobs = $this->claimAll();
        $this->assertSame('only one variant here', $jobs[0]['payload']['message']);
    }

    public function testAPerBotMessageLimitOverridesTheGlobalOne(): void
    {
        $this->incoming('geia');
        $this->setBotColumn('bot_max_messages', 1);
        $this->exhaustBudget(1);
        $this->llm->reply = 'feugw';

        $this->assertStringContainsString('limit reached (1/1)', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    // ------------------------------------------------------------------
    // processDeliverJob
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function deliverPayload(string $message = 'kala esy;', bool $farewell = false): array
    {
        return $this->replyPayload(0) + ['message' => $message, 'is_farewell' => $farewell];
    }

    public function testDeliveryStoresAndCountsTheMessage(): void
    {
        $result = $this->bot->processDeliverJob($this->deliverPayload('kala esy;'));

        $this->assertStringContainsString('delivered reply', $result);

        $stmt = $this->pdo->prepare(
            'SELECT message, from_username, to_username, from_session_id, to_session_id
             FROM private_messages WHERE from_username = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->nick]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('kala esy;', $row['message']);
        $this->assertSame($this->peer, $row['to_username']);
        $this->assertSame('fake_' . md5($this->nick), $row['from_session_id']);
        $this->assertSame($this->peerSession, $row['to_session_id']);

        $thread = $this->threadRow();
        $this->assertSame(1, (int) $thread['messages_sent']);
        $this->assertNotNull($thread['last_reply_at']);
        $this->assertNull($thread['farewell_sent_at']);
    }

    public function testDeliveringTheFarewellEndsTheConversation(): void
    {
        $this->bot->processDeliverJob($this->deliverPayload('ta leme!', true));

        $thread = $this->threadRow();
        $this->assertNotNull($thread['farewell_sent_at']);
        $this->assertSame(1, (int) $thread['messages_sent']);
    }

    public function testDeliveryPrefersTheRecipientsCurrentSession(): void
    {
        // The peer reconnected with a new session id since the job was queued.
        $newSession = 'sess_new_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat) VALUES (?, ?, ?, NOW())'
        )->execute([$this->peer, $newSession, '127.0.0.1']);

        $this->bot->processDeliverJob($this->deliverPayload());

        $stmt = $this->pdo->prepare(
            'SELECT to_session_id FROM private_messages WHERE from_username = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->nick]);

        $this->assertSame($newSession, $stmt->fetchColumn());
    }

    public function testDeliveryIsSkippedWithoutAnyKnownSession(): void
    {
        $payload = $this->deliverPayload();
        $payload['peer_session_id'] = '';

        $this->assertStringContainsString('no known session', $this->bot->processDeliverJob($payload));
    }

    public function testDeliveryWithAMalformedPayloadIsSkipped(): void
    {
        $this->assertStringContainsString('malformed', $this->bot->processDeliverJob([]));
        $this->assertStringContainsString('malformed', $this->bot->processDeliverJob($this->replyPayload(0)));
    }

    public function testAlreadyGeneratedRepliesAreDroppedAfterATakeover(): void
    {
        // The admin takes over while the bot is "typing".
        $this->bot->takeOverThread($this->nick, $this->peer, 'admin');

        $this->assertStringContainsString('taken over', $this->bot->processDeliverJob($this->deliverPayload()));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ?');
        $stmt->execute([$this->nick]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAComposedReplyStillDeliversAfterTheBotIsRotatedOffline(): void
    {
        // The rotation pulled the bot offline during the typing delay. The reply
        // is already written, so it must still be delivered, not dropped.
        $this->setBotColumn('is_active', false);

        $result = $this->bot->processDeliverJob($this->deliverPayload('kalispera'));
        $this->assertStringContainsString('delivered reply', $result);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ? AND to_username = ?');
        $stmt->execute([$this->nick, $this->peer]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testForceReplyClearsStuckStatesAndQueuesAReply(): void
    {
        // A thread stuck every way it can be: ignored, ended, taken over, spent.
        $this->pdo->prepare(
            'INSERT INTO bot_threads
                (fake_user_id, peer_username, is_ignored, ignore_decided_at, farewell_sent_at, is_taken_over, messages_sent)
             VALUES (?, ?, TRUE, NOW(), NOW(), TRUE, 99)'
        )->execute([$this->fakeUserId, $this->peer]);

        $this->assertTrue($this->bot->forceReply($this->nick, $this->peer));

        $thread = $this->threadRow();
        $this->assertFalse((bool) $thread['is_ignored']);
        $this->assertNull($thread['farewell_sent_at']);
        $this->assertFalse((bool) $thread['is_taken_over']);
        $this->assertSame(0, (int) $thread['messages_sent']);

        $jobs = $this->claimAll();
        $this->assertNotEmpty(array_filter($jobs, fn ($j) => $j['type'] === BotService::JOB_REPLY));
    }

    /**
     * Force-stop silences the bot in a single conversation (marks it ended so the
     * guard skips it) and is fully reversible with Force.
     */
    public function testStopThreadEndsTheConversationAndIsReversible(): void
    {
        // stopThread creates the thread if needed and marks it ended.
        $this->assertTrue($this->bot->stopThread($this->nick, $this->peer));
        $this->assertNotNull($this->threadRow()['farewell_sent_at'], 'force stop marks the thread ended');

        // Force brings the bot back — nothing is stuck permanently.
        $this->assertTrue($this->bot->forceReply($this->nick, $this->peer));
        $this->assertNull($this->threadRow()['farewell_sent_at']);
    }

    /**
     * The Bot Activity conversation view must show the RECENT messages of a long
     * thread, not the oldest 200 (which hid new messages, incl. those just sent).
     */
    public function testThreadMessagesShowsTheRecentWindowNotTheOldest(): void
    {
        $total = 210; // more than the 200-message window
        $values = [];
        $params = [];
        for ($i = 1; $i <= $total; $i++) {
            $from = $i % 2 === 1 ? $this->peer : $this->nick;
            $to = $i % 2 === 1 ? $this->nick : $this->peer;
            $values[] = "(?, ?, ?, ?, ?, NOW() + (? || ' seconds')::interval)";
            array_push($params, $from, 'sess_' . $from, $to, 'fake_' . md5($this->nick), 'm#' . $i, $i);
        }
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES ' . implode(',', $values)
        )->execute($params);

        $bodies = array_column($this->bot->threadMessages($this->nick, $this->peer), 'message');

        $this->assertCount(200, $bodies, 'capped at the 200-message window');
        $this->assertContains('m#' . $total, $bodies, 'the newest message must be shown');
        $this->assertNotContains('m#1', $bodies, 'the oldest is outside the window');
        // Chronological (oldest-first) within the recent window.
        $this->assertSame('m#' . ($total - 199), $bodies[0]);
        $this->assertSame('m#' . $total, $bodies[array_key_last($bodies)]);
    }

    public function testAMultiLineReplyIsDeliveredAsSeparateBubblesCountingOneTurn(): void
    {
        $this->incoming('γεια');
        $this->llm->reply = "γεια!\nτι κανεις;"; // two lines

        $this->bot->processReplyJob($this->replyPayload(0));

        $jobs = array_values(array_filter(
            $this->claimAll(),
            fn ($j) => $j['type'] === BotService::JOB_DELIVER
        ));
        $this->assertCount(2, $jobs, 'each line becomes its own bubble');

        $messages = array_map(fn ($j) => $j['payload']['message'], $jobs);
        sort($messages);
        $this->assertSame(['γεια!', 'τι κανεις;'], $messages);

        // Exactly one bubble is the final one (counts the turn, may end the chat).
        $finals = array_filter($jobs, fn ($j) => $j['payload']['is_final']);
        $this->assertCount(1, $finals);

        // Delivering both spends the budget once, not twice.
        foreach ($jobs as $job) {
            $this->bot->processDeliverJob($job['payload']);
        }
        $this->assertSame(1, (int) $this->threadRow()['messages_sent']);
    }

    /**
     * A greeklish peer gets a transliterated reply, but the model's Greek source
     * is preserved (original_message on the delivery job, bot_original_message on
     * the row) and fed back as history — the LLM must never see its own greeklish,
     * or it starts writing greeklish itself.
     */
    public function testGreeklishReplyKeepsTheGreekSourceOutOfTheReadersViewButInHistory(): void
    {
        $this->settings->values['bot_emoji_chance'] = '0';
        $this->incoming('ti kaneis re');   // peer writes greeklish (latin) → greeklish reply
        $this->llm->reply = 'καλά, εσύ;';   // the model answers in Greek script

        $this->bot->processReplyJob($this->replyPayload(0));

        $deliver = array_values(array_filter(
            $this->claimAll(),
            fn ($j) => $j['type'] === BotService::JOB_DELIVER
        ))[0];

        // Reader sees greeklish; the job carries the untouched Greek source.
        $this->assertSame('kala, esy;', $deliver['payload']['message']);
        $this->assertSame('καλά, εσύ;', $deliver['payload']['original_message']);

        $this->bot->processDeliverJob($deliver['payload']);

        $stmt = $this->pdo->prepare(
            'SELECT message, bot_original_message FROM private_messages
             WHERE from_username = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->nick]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('kala, esy;', $row['message']);
        $this->assertSame('καλά, εσύ;', $row['bot_original_message']);

        // The next reply's history carries the Greek, never the greeklish.
        $this->incoming('ωραία');
        $this->llm->calls = [];
        $this->bot->processReplyJob($this->replyPayload(0));
        $flat = json_encode(end($this->llm->calls)['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('καλά, εσύ;', $flat);
        $this->assertStringNotContainsString('kala, esy;', $flat);
    }

    /**
     * With the emoji chance at zero, every emoji is stripped from the delivered
     * message — a bot that sprinkles emoji on each reply is a giveaway.
     */
    public function testEmojisAreStrippedFromTheDeliveredMessageWhenTheChanceIsZero(): void
    {
        $this->settings->values['bot_emoji_chance'] = '0';
        $this->incoming('γεια');
        $this->llm->reply = 'γεια 😅';

        $this->bot->processReplyJob($this->replyPayload(0));

        $deliver = array_values(array_filter(
            $this->claimAll(),
            fn ($j) => $j['type'] === BotService::JOB_DELIVER
        ))[0];
        $this->assertSame('γεια', $deliver['payload']['message']);
    }

    /**
     * The reply's system prompt (not a buried history note, so the model honours
     * it) must carry the current day and time, so replies fit reality — no "just
     * got back from work" on a Sunday.
     */
    public function testTheCurrentDayAndTimeAreInTheSystemPrompt(): void
    {
        $this->incoming('geia');
        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('ΠΡΑΓΜΑΤΙΚΟΤΗΤΑ ΤΩΡΑ', end($this->llm->calls)['system']);
    }

    public function testTheMultiMessageDirectiveFollowsTheConfiguredChance(): void
    {
        $this->incoming('geia');

        $this->settings->values['bot_multi_message_chance'] = '100';
        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertStringContainsString('ΑΥΤΗ ΤΗ ΦΟΡΑ', end($this->llm->calls)['system']);

        $this->llm->calls = [];
        $this->settings->values['bot_multi_message_chance'] = '0';
        $this->incoming('kai kati allo');
        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertStringNotContainsString('ΑΥΤΗ ΤΗ ΦΟΡΑ', end($this->llm->calls)['system']);
    }

    public function testDeliveryIsDroppedWhenSupersededByANewerMessage(): void
    {
        $this->incoming('older');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);
        $this->incoming('newer');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);
        $this->queue->flush();

        $stale = $this->deliverPayload();
        $stale['epoch'] = $this->currentEpoch() - 1;
        $this->assertGreaterThan(0, $stale['epoch'], 'epoch 0 disables the check');

        $this->assertStringContainsString('superseded', $this->bot->processDeliverJob($stale));
    }

    // ------------------------------------------------------------------
    // Takeover / release / state
    // ------------------------------------------------------------------

    public function testTakeoverAndReleaseFlipTheThreadState(): void
    {
        $this->assertTrue($this->bot->takeOverThread($this->nick, $this->peer, 'root'));

        $state = $this->bot->getThreadState($this->nick, $this->peer);
        $this->assertTrue($state['is_taken_over']);
        $this->assertSame('root', $state['taken_over_by']);
        $this->assertNotNull($state['taken_over_at']);

        $this->assertTrue($this->bot->releaseThread($this->nick, $this->peer));

        $state = $this->bot->getThreadState($this->nick, $this->peer);
        $this->assertFalse($state['is_taken_over']);
        $this->assertNull($state['taken_over_by']);
    }

    public function testReleaseCanResetTheBudgetAndTheEndedFlag(): void
    {
        $this->exhaustBudget();
        $this->pdo->prepare('UPDATE bot_threads SET farewell_sent_at = NOW() WHERE fake_user_id = ?')
            ->execute([$this->fakeUserId]);

        $this->bot->releaseThread($this->nick, $this->peer, true);

        $state = $this->bot->getThreadState($this->nick, $this->peer);
        $this->assertSame(0, $state['messages_sent']);
        $this->assertNull($state['farewell_sent_at']);
    }

    public function testTakeoverInvalidatesQueuedWork(): void
    {
        $this->incoming('geia');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);
        $epochBefore = $this->currentEpoch();

        $this->bot->takeOverThread($this->nick, $this->peer, 'root');

        $this->assertGreaterThan($epochBefore, $this->currentEpoch());
    }

    public function testTakeoverAndReleaseReportFailureForAnUnknownFakeUser(): void
    {
        $this->assertFalse($this->bot->takeOverThread('no_such_bot_xyz', $this->peer));
        $this->assertFalse($this->bot->releaseThread('no_such_bot_xyz', $this->peer));
        $this->assertNull($this->bot->getThreadState('no_such_bot_xyz', $this->peer));
    }

    public function testThreadStateReportsTheBudgetAndSwitches(): void
    {
        $state = $this->bot->getThreadState($this->nick, $this->peer);

        $this->assertTrue($state['globally_enabled']);
        $this->assertTrue($state['bot_enabled']);
        $this->assertSame(0, $state['messages_sent']);
        $this->assertSame(4, $state['max_messages']);

        $this->setBotColumn('bot_max_messages', 9);
        $this->assertSame(9, $this->bot->getThreadState($this->nick, $this->peer)['max_messages']);
    }

    public function testIsEnabledFollowsTheSetting(): void
    {
        $this->assertTrue($this->bot->isEnabled());

        $this->settings->values['bot_replies_enabled'] = 'false';
        $this->assertFalse($this->bot->isEnabled());
    }

    // ------------------------------------------------------------------
    // Rolling summary of what fell out of the history window
    // ------------------------------------------------------------------

    /** Fill the thread with more messages than the window holds. */
    private function longThread(int $pairs): void
    {
        for ($i = 1; $i <= $pairs; $i++) {
            $this->incoming("user message {$i}");
            $this->botSaid("bot message {$i}");
        }
        $this->incoming('kai twra ti;');
    }

    public function testNothingIsSummarisedWhileEverythingFitsInTheWindow(): void
    {
        $this->settings->values['bot_history_limit'] = '20';
        $this->incoming('geia');

        $this->bot->processReplyJob($this->replyPayload(0));

        // One call only: the reply. No summary needed.
        $this->assertCount(1, $this->llm->calls);
        $this->assertNull($this->threadRow()['summary']);
    }

    public function testOlderMessagesAreSummarisedOnceTheWindowSlides(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->llm->reply = 'Ο συνομιλητής λέγεται Νίκος και δουλεύει σε μπαρ.';

        $this->bot->processReplyJob($this->replyPayload(0));

        // Three calls: the summary, the self-facts extraction, then the reply.
        $this->assertCount(3, $this->llm->calls);
        $this->assertStringContainsString('Συνόψισε', $this->llm->calls[0]['system']);
        $this->assertStringContainsString('user message 1', $this->llm->calls[0]['messages'][0]['content']);

        $stored = (string) $this->threadRow()['summary'];
        $this->assertNotSame('', $stored);
        $this->assertNotNull($this->threadRow()['summary_upto_id']);

        // ...and the reply (the last call) carries it.
        $reply = end($this->llm->calls);
        $this->assertStringContainsString('Τι έχει προηγηθεί', $reply['system']);
        $this->assertStringContainsString($stored, $reply['system']);
    }

    public function testSelfFactsCanonIsBuiltOnTheSummaryCadence(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->llm->reply = 'ύψος 1.60, μπλε μαλλιά, πράσινα μάτια';

        $this->bot->processReplyJob($this->replyPayload(0));

        // A self-facts extraction ran (its instruction is about the "canon")...
        $ran = false;
        foreach ($this->llm->calls as $call) {
            if (str_contains($call['system'], 'canon')) { $ran = true; break; }
        }
        $this->assertTrue($ran, 'a self-facts extraction should run on the summary cadence');

        // ...and the canon was stored on the fake user (bot-level, not per-thread).
        $stmt = $this->pdo->prepare('SELECT bot_self_facts FROM fake_users WHERE id = ?');
        $stmt->execute([$this->fakeUserId]);
        $this->assertNotSame('', (string) $stmt->fetchColumn());
    }

    public function testASummaryIsNotRedoneForEveryNewMessage(): void
    {
        // Every message pushes one out of the window, so summarising per message
        // would double the API calls. It is batched instead.
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertCount(3, $this->llm->calls, 'first reply summarises and updates self-facts');

        $this->llm->calls = [];
        $this->incoming('kai kati allo');
        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertCount(1, $this->llm->calls, 'one dropped message must not trigger a new summary');
        $this->assertStringContainsString('Τι έχει προηγηθεί', $this->llm->calls[0]['system']);
    }

    public function testMessagesWaitingForTheNextSummaryStayInTheHistory(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->bot->processReplyJob($this->replyPayload(0));

        // This one drops out of the window but is not summarised yet, so it must
        // still be sent verbatim rather than vanish.
        $this->llm->calls = [];
        $this->incoming('THE PENDING ONE');
        $this->incoming('kai meta;');
        $this->bot->processReplyJob($this->replyPayload(0));

        $sent = json_encode($this->llm->calls[0]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('THE PENDING ONE', (string) $sent);
    }

    public function testTheSummaryIsRefreshedOnceEnoughMessagesHaveDroppedOut(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->bot->processReplyJob($this->replyPayload(0));
        $firstUpto = (int) $this->threadRow()['summary_upto_id'];

        // Push a whole batch out of the window.
        $this->llm->calls = [];
        $this->longThread(4);
        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertCount(3, $this->llm->calls, 'new dropped messages need a fresh summary (+ self-facts + reply)');
        // The previous summary is fed back in rather than re-reading everything.
        $this->assertStringContainsString('Περίληψη μέχρι τώρα', $this->llm->calls[0]['messages'][0]['content']);
        $this->assertGreaterThan($firstUpto, (int) $this->threadRow()['summary_upto_id']);
    }

    public function testSummarisingCanBeTurnedOff(): void
    {
        $this->settings->values['bot_summary_enabled'] = 'false';
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertCount(1, $this->llm->calls);
        $this->assertNull($this->threadRow()['summary']);
    }

    public function testAFailedSummaryStillLetsTheReplyThrough(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);

        // The failure is logged on purpose; assert it instead of leaking it.
        $this->expectAppLogMatches('/summary failed, continuing without it/');

        // Fail the first call (the summary) and succeed on the reply.
        $bot = new BotService($this->settings, $this->queue, new FailFirstLlm());

        $result = $bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('queued reply', $result);
        $this->assertNull($this->threadRow()['summary']);
    }

    public function testResettingAThreadDropsItsSummary(): void
    {
        $this->settings->values['bot_history_limit'] = '4';
        $this->longThread(5);
        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertNotNull($this->threadRow()['summary']);

        $this->bot->releaseThread($this->nick, $this->peer, true);

        $this->assertNull($this->threadRow()['summary']);
    }

    // ------------------------------------------------------------------
    // Admin overview (Bot Activity tab)
    // ------------------------------------------------------------------

    public function testThreadsAreListedWithTheirBudgetAndCounts(): void
    {
        $this->incoming('geia');
        $this->botSaid('geia sou');
        $this->exhaustBudget(2);

        $mine = null;
        foreach ($this->bot->listThreads() as $thread) {
            if ($thread['nickname'] === $this->nick) {
                $mine = $thread;
            }
        }

        $this->assertNotNull($mine, 'the thread should appear in the admin list');
        $this->assertSame($this->peer, $mine['peer_username']);
        $this->assertSame(2, $mine['messages_sent']);
        $this->assertSame(4, $mine['max_messages']);
        $this->assertSame(2, $mine['message_count']);
        $this->assertTrue($mine['bot_enabled']);
        $this->assertFalse($mine['is_taken_over']);
    }

    public function testAPerBotLimitIsReflectedInTheList(): void
    {
        $this->setBotColumn('bot_max_messages', 9);
        $this->exhaustBudget(1);

        foreach ($this->bot->listThreads() as $thread) {
            if ($thread['nickname'] === $this->nick) {
                $this->assertSame(9, $thread['max_messages']);

                return;
            }
        }

        $this->fail('thread not listed');
    }

    public function testTakeoverShowsUpInTheList(): void
    {
        $this->bot->takeOverThread($this->nick, $this->peer, 'root');

        foreach ($this->bot->listThreads() as $thread) {
            if ($thread['nickname'] === $this->nick) {
                $this->assertTrue($thread['is_taken_over']);
                $this->assertSame('root', $thread['taken_over_by']);

                return;
            }
        }

        $this->fail('thread not listed');
    }

    public function testThreadMessagesComeBackOldestFirstAndTagged(): void
    {
        $this->incoming('first from user');
        $this->botSaid('then the bot');
        $this->incoming('and the user again');

        $messages = $this->bot->threadMessages($this->nick, $this->peer);

        $this->assertCount(3, $messages);
        $this->assertSame('first from user', $messages[0]['message']);
        $this->assertFalse($messages[0]['is_bot']);
        $this->assertTrue($messages[1]['is_bot'], "the bot's own turn must be marked");
        $this->assertSame('and the user again', $messages[2]['message']);
    }

    public function testThreadMessagesOnlyCoverThatConversation(): void
    {
        $this->incoming('for me');
        $other = 'peer_other_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$other, 's2', $this->nick, 'fake_' . md5($this->nick), 'for someone else']);

        try {
            $messages = $this->bot->threadMessages($this->nick, $this->peer);

            $this->assertCount(1, $messages);
            $this->assertSame('for me', $messages[0]['message']);
        } finally {
            $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$other]);
        }
    }

    // ------------------------------------------------------------------
    // Clearing a bot's history (for re-testing)
    // ------------------------------------------------------------------

    public function testClearingHistoryRemovesMessagesThreadsAndQueuedWork(): void
    {
        $this->incoming('geia');
        $this->botSaid('geia sou');
        $this->exhaustBudget(4);
        $this->bot->takeOverThread($this->nick, $this->peer, 'admin');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        $cleared = $this->bot->clearHistoryFor($this->nick);

        $this->assertSame(2, $cleared['messages']);
        $this->assertSame(1, $cleared['threads']);
        $this->assertGreaterThanOrEqual(1, $cleared['epochs']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ? OR to_username = ?');
        $stmt->execute([$this->nick, $this->nick]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertNull($this->threadRow());
        $this->assertSame(0, $this->currentEpoch(), 'a queued reply must not survive the wipe');
    }

    public function testAClearedBotStartsFromAFreshBudget(): void
    {
        $this->exhaustBudget(4);
        $this->assertSame(4, $this->bot->getThreadState($this->nick, $this->peer)['messages_sent']);

        $this->bot->clearHistoryFor($this->nick);

        $this->assertSame(0, $this->bot->getThreadState($this->nick, $this->peer)['messages_sent']);
        $this->assertFalse($this->bot->getThreadState($this->nick, $this->peer)['is_taken_over']);
    }

    public function testClearingAnUnknownBotIsHarmless(): void
    {
        $this->assertSame(
            ['messages' => 0, 'threads' => 0, 'epochs' => 0],
            $this->bot->clearHistoryFor('no_such_bot_xyz')
        );
    }

    public function testClearingOneBotLeavesOthersAlone(): void
    {
        $otherNick = 'bot_keep_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, TRUE)')
            ->execute([$otherNick]);
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$this->peer, $this->peerSession, $otherNick, 'fake_' . md5($otherNick), 'geia']);

        try {
            $this->incoming('geia');
            $this->bot->clearHistoryFor($this->nick);

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE to_username = ?');
            $stmt->execute([$otherNick]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), "another bot's messages must survive");
        } finally {
            $this->pdo->prepare('DELETE FROM private_messages WHERE to_username = ? OR from_username = ?')
                ->execute([$otherNick, $otherNick]);
            $this->pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$otherNick]);
        }
    }

    // ------------------------------------------------------------------
    // History building
    // ------------------------------------------------------------------

    public function testHistoryAlternatesRolesAndMergesConsecutiveTurns(): void
    {
        $this->incoming('geia');
        $this->incoming('eisai ekei;');   // two user turns in a row
        $this->botSaid('nai pes mou');
        $this->incoming('ti kaneis;');

        $this->bot->processReplyJob($this->replyPayload(0));

        $messages = $this->llm->calls[0]['messages'];
        $roles = array_column($messages, 'role');

        // The conversation turns (a trailing system note about time follows).
        $this->assertSame(['user', 'assistant', 'user'], array_slice($roles, 0, 3));
        $this->assertStringContainsString('geia', $messages[0]['content']);
        $this->assertStringContainsString('eisai ekei;', $messages[0]['content']);
    }

    public function testNoReplyWhenTheBotSpokeLast(): void
    {
        // Nothing new to answer, so the bot must not double-message the peer.
        $this->incoming('geia');
        $this->botSaid('ti leei;');

        $this->assertStringContainsString('no conversation history', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    public function testAttachmentOnlyMessagesBecomeAPlaceholder(): void
    {
        $this->pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
             VALUES (?, ?, ?, ?, '', ?, NOW())"
        )->execute([$this->peer, $this->peerSession, $this->nick, 'fake_' . md5($this->nick), 'att_' . uniqid()]);

        $this->bot->processReplyJob($this->replyPayload(0));

        $messages = $this->llm->calls[0]['messages'];
        $this->assertStringContainsString('φωτογραφία', $messages[0]['content']);
    }

    /**
     * A photo sent WITH a caption used to be invisible: the attachment was only
     * turned into a marker when the text was empty, so the bot answered the caption
     * as if nothing had been attached.
     */
    public function testAPhotoWithACaptionStillTellsTheBotAPhotoArrived(): void
    {
        $this->pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $this->peer,
            $this->peerSession,
            $this->nick,
            'fake_' . md5($this->nick),
            'δες εδώ',
            'att_' . uniqid(),
        ]);

        $this->bot->processReplyJob($this->replyPayload(0));

        $content = $this->llm->calls[0]['messages'][0]['content'];
        $this->assertStringContainsString(BotService::PHOTO_MARKER_IN, $content);
        $this->assertStringContainsString('δες εδώ', $content, 'the caption must survive too');
    }

    public function testThePromptExplainsHowToHandleAPhotoItCannotSee(): void
    {
        $this->incoming('geia');
        $this->bot->processReplyJob($this->replyPayload(0));

        $prompt = $this->llm->calls[0]['system'];

        // The image is never uploaded, so the bot has to react without describing it
        // and without admitting it is a program that cannot see.
        $this->assertStringContainsString('ΦΩΤΟΓΡΑΦΙΕΣ', $prompt);
        $this->assertStringContainsString(BotService::PHOTO_MARKER_IN, $prompt);
    }

    // ------------------------------------------------------------------
    // Reply language
    // ------------------------------------------------------------------

    /**
     * Asking for greeklish in the persona was being ignored, so the choice is now
     * an explicit setting, instructed last in the prompt AND enforced on the reply.
     */
    public function testAGreeklishBotIsInstructedAndItsReplyIsTransliterated(): void
    {
        $this->setBotColumn('bot_reply_language', 'greeklish');
        $this->incoming('γεια, τι κανεις;');
        $this->llm->reply = 'καλά είμαι ρε, εσύ;';

        $this->bot->processReplyJob($this->replyPayload(0));

        $prompt = $this->llm->calls[0]['system'];
        // The bot is told to write natural Greek; the greeklish outcome is
        // produced by transliterating the reply (asserted below), not by forcing
        // the model to type latin characters.
        $this->assertStringContainsString('ΓΛΩΣΣΑ - ΥΠΟΧΡΕΩΤΙΚΟ', $prompt);
        $this->assertStringContainsString('ΕΛΛΗΝΙΚΟΥΣ χαρακτήρες', $prompt);

        $delivered = $this->claimAll();
        $this->assertSame('kala eimai re, esy;', $delivered[0]['payload']['message']);
    }

    public function testAGreekBotIsLeftAlone(): void
    {
        $this->setBotColumn('bot_reply_language', 'greek');
        $this->incoming('geia');
        $this->llm->reply = 'καλά είμαι ρε';

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertSame('καλά είμαι ρε', $this->claimAll()[0]['payload']['message']);
    }

    public function testAutoModeWritesGreekAndConvertsToGreeklishForAGreeklishPeer(): void
    {
        $this->incoming('ti kaneis re');   // the peer writes greeklish (latin)
        $this->llm->reply = 'καλά εσύ;';   // the model replies in GREEK, as instructed

        $this->bot->processReplyJob($this->replyPayload(0));

        // The model is told to write GREEK characters and never greeklish itself.
        $system = $this->llm->calls[0]['system'];
        $this->assertStringContainsString('ΕΛΛΗΝΙΚΟΥΣ χαρακτήρες', $system);
        $this->assertStringNotContainsString('ίδιο αλφάβητο', $system);

        // The system — not the model — converts the reply to greeklish for the
        // greeklish peer.
        $this->assertSame('kala esy;', $this->claimAll()[0]['payload']['message']);
    }

    public function testAnInvalidLanguageValueFallsBackToAuto(): void
    {
        $this->assertSame('auto', BotService::replyLanguage(['bot_reply_language' => 'klingon']));
        $this->assertSame('auto', BotService::replyLanguage([]));
    }

    // ------------------------------------------------------------------
    // Some conversations are never picked up
    // ------------------------------------------------------------------

    /**
     * A bot that answers every stranger within seconds is a tell, so a share of new
     * conversations is ignored outright.
     */
    public function testAnIgnoredConversationSchedulesNothingAtAll(): void
    {
        $this->setBotColumn('bot_ignore_chance', 100);
        $this->incoming('geia');

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertSame([], $this->claimAll(), 'no job means no LLM call and no cost');
        $this->assertTrue((bool) $this->threadRow()['is_ignored']);
    }

    public function testAnIgnoredConversationStaysIgnoredForEveryFurtherMessage(): void
    {
        $this->setBotColumn('bot_ignore_chance', 100);

        foreach (['geia', 'eisai ekei;', 'hello?'] as $text) {
            $this->incoming($text);
            $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        }

        // Silence has to be consistent: answering the third message would be worse
        // than never answering at all.
        $this->assertSame([], $this->claimAll());
    }

    public function testZeroChanceAlwaysReplies(): void
    {
        $this->setBotColumn('bot_ignore_chance', 0);
        $this->incoming('geia');

        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertFalse((bool) $this->threadRow()['is_ignored']);
        $this->assertNotNull($this->threadRow()['ignore_decided_at'], 'the decision is recorded either way');
    }

    /**
     * The dice are rolled once. Without that, a peer sending three messages before
     * the first reply lands gets three rolls, which multiplies the real ignore rate.
     */
    public function testTheDecisionIsTakenOnlyOnce(): void
    {
        $this->setBotColumn('bot_ignore_chance', 0);
        $this->incoming('geia');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        $decidedAt = $this->threadRow()['ignore_decided_at'];

        // Now make ignoring certain: the answer must not change.
        $this->setBotColumn('bot_ignore_chance', 100);
        $this->incoming('eisai ekei;');

        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertFalse((bool) $this->threadRow()['is_ignored']);
        $this->assertSame($decidedAt, $this->threadRow()['ignore_decided_at']);
    }

    /**
     * "Not interested from the start" is human; going quiet halfway through reads as
     * a broken bot.
     */
    public function testABotNeverStartsIgnoringMidConversation(): void
    {
        $this->incoming('geia');
        $this->botSaid('ti leei;');
        // A conversation already under way: the bot has spoken once.
        $this->pdo->prepare(
            'INSERT INTO bot_threads (fake_user_id, peer_username, messages_sent) VALUES (?, ?, 1)'
        )->execute([$this->fakeUserId, $this->peer]);

        $this->setBotColumn('bot_ignore_chance', 100);
        $this->incoming('apo pou eisai;');

        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertFalse((bool) $this->threadRow()['is_ignored']);
    }

    public function testThePerBotChanceOverridesTheGlobalOne(): void
    {
        $this->settings->values['bot_ignore_chance'] = '100';

        $this->assertSame(100, $this->bot->ignoreChanceFor([]), 'the global setting applies by default');
        $this->assertSame(0, $this->bot->ignoreChanceFor(['bot_ignore_chance' => 0]), 'a per-bot 0 must win');
        // Out-of-range values are clamped rather than trusted.
        $this->assertSame(100, $this->bot->ignoreChanceFor(['bot_ignore_chance' => 250]));
        $this->assertSame(0, $this->bot->ignoreChanceFor(['bot_ignore_chance' => -5]));
    }

    public function testAnIgnoredConversationIsVisibleToTheAdmin(): void
    {
        $this->setBotColumn('bot_ignore_chance', 100);
        $this->incoming('geia');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession);

        // Otherwise silence is indistinguishable from a broken bot.
        $this->assertTrue($this->bot->getThreadState($this->nick, $this->peer)['is_ignored']);

        $threads = array_values(array_filter(
            $this->bot->listThreads(200),
            fn (array $t): bool => $t['nickname'] === $this->nick && $t['peer_username'] === $this->peer
        ));
        $this->assertTrue((bool) $threads[0]['is_ignored']);
    }

    // ------------------------------------------------------------------
    // Repeated abuse ends in a block
    // ------------------------------------------------------------------

    public function testASingleInsultDoesNotBlockAnyone(): void
    {
        // In Greek chat one insult is often banter; blocking on it would end
        // conversations between people getting along.
        $this->incoming('ηλιθια');

        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'ηλιθια'));
        $this->assertSame(1, (int) $this->threadRow()['insult_count']);
        $this->assertNull($this->threadRow()['blocked_at']);
        $this->assertFalse((new BlockService())->hasBlocked($this->nick, $this->peer));
    }

    /**
     * With the immediate-block chance at 100%, a single abusive message blocks
     * the peer on the spot, before the strike threshold is reached.
     */
    public function testAnAbusiveMessageCanBlockImmediately(): void
    {

        $this->settings->values['bot_immediate_block_chance'] = '100';
        $this->incoming('poutana');

        $replied = $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'poutana');

        $this->assertFalse($replied, 'no normal reply — it blocks instead');
        $thread = $this->threadRow();
        $this->assertSame(1, (int) $thread['insult_count'], 'blocked on the first strike');
        $this->assertNotNull($thread['blocked_at']);
        $this->assertTrue((new BlockService())->hasBlocked($this->nick, $this->peer));
    }

    /**
     * Context matters: hostile/dismissive words are always abuse, but sexual/
     * anatomical words are only abuse OUTSIDE an erotic (explicit) persona's chat.
     */
    public function testLooksAbusiveSplitsHostileFromSexual(): void
    {
        // Hostile — abuse regardless of the explicit flag.
        $this->assertTrue(BotService::looksAbusive('αντε γαμησου', false));
        $this->assertTrue(BotService::looksAbusive('αντε γαμησου', true));
        $this->assertTrue(BotService::looksAbusive('εισαι ηλιθια', true));

        // Sexual/anatomical — abuse only when NOT explicit.
        $this->assertTrue(BotService::looksAbusive('γλυψε μου τα αρχιδια', false));
        $this->assertFalse(BotService::looksAbusive('γλυψε μου τα αρχιδια', true));
        $this->assertFalse(BotService::looksAbusive('πουτανα', true));
    }

    /** An explicit persona doesn't block on consensual dirty talk. */
    public function testExplicitBotDoesNotBlockOnSexualWords(): void
    {
        $this->setBotColumn('bot_allow_explicit', true);
        $this->settings->values['bot_immediate_block_chance'] = '100'; // would block if flagged

        $replied = $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'γλυψε μου τα αρχιδια');

        $this->assertTrue($replied, 'an explicit persona replies — sexual words are not abuse');
        $this->assertNull($this->threadRow()['blocked_at']);
    }

    /** …but a hostile insult still blocks, even for an explicit persona. */
    public function testExplicitBotStillBlocksOnHostileInsult(): void
    {
        $this->setBotColumn('bot_allow_explicit', true);
        $this->settings->values['bot_immediate_block_chance'] = '100';

        $replied = $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'αντε γαμησου');

        $this->assertFalse($replied, 'a hostile insult still blocks');
        $this->assertNotNull($this->threadRow()['blocked_at']);
    }

    /** Force/Steer also lift an abuse block so the bot talks again. */
    public function testForceReplyLiftsAnAbuseBlock(): void
    {
        // Put the thread in a blocked state and add the DM block.
        $this->pdo->prepare(
            'UPDATE bot_threads SET blocked_at = NOW(), insult_count = 3
             WHERE fake_user_id = ? AND peer_username = ?'
        )->execute([$this->fakeUserId, $this->peer]);
        // Ensure a thread row exists first.
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'geia');
        $this->pdo->prepare(
            'UPDATE bot_threads SET blocked_at = NOW(), insult_count = 3
             WHERE fake_user_id = ? AND peer_username = ?'
        )->execute([$this->fakeUserId, $this->peer]);

        $this->bot->forceReply($this->nick, $this->peer);

        $row = $this->threadRow();
        $this->assertNull($row['blocked_at']);
        $this->assertSame(0, (int) $row['insult_count']);
    }

    /** Unblock lifts the block WITHOUT forcing a reply. */
    public function testUnblockThreadClearsTheBlock(): void
    {
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'geia');
        $this->pdo->prepare(
            'UPDATE bot_threads SET blocked_at = NOW(), insult_count = 2
             WHERE fake_user_id = ? AND peer_username = ?'
        )->execute([$this->fakeUserId, $this->peer]);

        $this->assertTrue($this->bot->unblockThread($this->nick, $this->peer));
        $row = $this->threadRow();
        $this->assertNull($row['blocked_at']);
        $this->assertSame(0, (int) $row['insult_count']);
    }

    /** Force/Steer bring an inactive/disabled fake user back online so it can reply. */
    public function testForceReplyReactivatesTheFakeUser(): void
    {
        $this->setBotColumn('is_active', false);
        $this->setBotColumn('bot_enabled', false);

        $this->assertTrue($this->bot->forceReply($this->nick, $this->peer));

        $stmt = $this->pdo->prepare('SELECT is_active, bot_enabled FROM fake_users WHERE id = ?');
        $stmt->execute([$this->fakeUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue((bool) $row['is_active']);
        $this->assertTrue((bool) $row['bot_enabled']);
    }

    public function testRepeatedAbuseMakesTheBotBlockThePeer(): void
    {

        foreach (['εισαι ηλιθια', 'poutana', 'αντε γαμησου'] as $i => $insult) {
            $this->incoming($insult);
            $replied = $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, $insult);

            // The third one is where it ends: no reply job, a block instead.
            $this->assertSame($i < 2, $replied, $insult);
        }

        $thread = $this->threadRow();
        $this->assertSame(3, (int) $thread['insult_count']);
        $this->assertNotNull($thread['blocked_at']);
        $this->assertStringContainsString('Blocked', (string) $thread['last_error']);

        // The same mechanism as the DM block button, so it behaves like a real one.
        $this->assertTrue((new BlockService())->hasBlocked($this->nick, $this->peer));
        $this->assertTrue((new BlockService())->isBlockedBetween($this->nick, $this->peer));
    }

    public function testTheBotSaysOneLastThingBeforeBlocking(): void
    {

        foreach (['ηλιθια', 'poutana', 'γαμησου'] as $insult) {
            $this->incoming($insult);
            $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, $insult);
        }

        $delivered = array_values(array_filter(
            $this->claimAll(),
            fn (array $job): bool => $job['type'] === 'bot_deliver'
        ));

        // Sent with the usual typing delay, so it reads like a person typing it
        // before hitting block - and it costs no API call.
        $this->assertNotEmpty($delivered);
        $last = end($delivered);
        $this->assertContains($last['payload']['message'], BotService::ABUSE_BRUSH_OFFS);
    }

    public function testABlockedThreadNeverReplaysAgain(): void
    {

        foreach (['ηλιθια', 'poutana', 'γαμησου'] as $insult) {
            $this->incoming($insult);
            $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, $insult);
        }

        // Even a polite message afterwards gets nothing: the block stands.
        $this->incoming('συγγνωμη, ας τα πουμε');

        $this->assertFalse(
            $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'συγγνωμη, ας τα πουμε')
        );
    }

    public function testTheThresholdIsConfigurableAndCanBeSwitchedOff(): void
    {
        $this->settings->values['bot_insult_block_threshold'] = '0';
        $this->assertSame(0, $this->bot->insultBlockThreshold());

        $this->incoming('poutana');
        $this->assertTrue($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'poutana'));

        // Disabled means nothing is even counted.
        $this->assertSame(0, (int) $this->threadRow()['insult_count']);
        $this->assertFalse((new BlockService())->hasBlocked($this->nick, $this->peer));
    }

    public function testTheAbuseIsJudgedOnTheStoredMessageWhenNoneIsPassed(): void
    {
        $this->settings->values['bot_insult_block_threshold'] = '1';

        // The worker and older callers do not pass the text.
        $this->incoming('αντε γαμησου ρε');

        $this->assertFalse($this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession));
        $this->assertNotNull($this->threadRow()['blocked_at']);
    }

    public function testTheBlockIsVisibleToTheAdmin(): void
    {
        $this->settings->values['bot_insult_block_threshold'] = '1';

        $this->incoming('poutana');
        $this->bot->onIncomingMessage($this->nick, $this->peer, $this->peerSession, 'poutana');

        $state = $this->bot->getThreadState($this->nick, $this->peer);
        $this->assertNotNull($state['blocked_at']);
        $this->assertSame(1, $state['insult_count']);

        $threads = array_values(array_filter(
            $this->bot->listThreads(200),
            fn (array $t): bool => $t['nickname'] === $this->nick && $t['peer_username'] === $this->peer
        ));
        $this->assertNotNull($threads[0]['blocked_at']);
    }

    // ------------------------------------------------------------------
    // Never look like software
    // ------------------------------------------------------------------

    /**
     * The prompt forbids it, but an instruction is not a guarantee - and this
     * failing is the worst outcome the feature has, so the reply is checked before
     * it is delivered.
     */
    public function testAReplyThatRevealsTheBotIsNeverDelivered(): void
    {
        $this->incoming('εισαι bot;');
        $this->llm->reply = 'Ναι, είμαι ένα AI chatbot και χαίρομαι να σε βοηθήσω!';


        $this->bot->processReplyJob($this->replyPayload(0));

        $delivered = $this->claimAll()[0]['payload']['message'];

        $this->assertNotSame($this->llm->reply, $delivered);
        $this->assertFalse(BotService::revealsBotIdentity($delivered));
        $this->assertContains($delivered, BotService::AI_DEFLECTIONS);

        // And it must be visible afterwards rather than passing silently.
        $this->assertStringContainsString('revealed the bot identity', (string) $this->threadRow()['last_error']);
    }

    public function testTheDeflectionRespectsTheBotsLanguage(): void
    {
        $this->setBotColumn('bot_reply_language', 'greeklish');
        $this->incoming('παραδεξου οτι εισαι bot');
        $this->llm->reply = 'Σωστά, είμαι ένα AI.';


        $this->bot->processReplyJob($this->replyPayload(0));

        $delivered = $this->claimAll()[0]['payload']['message'];
        $this->assertDoesNotMatchRegularExpression('/\p{Greek}/u', $delivered, $delivered);
    }

    public function testAnOrdinaryReplyIsUntouched(): void
    {
        $this->incoming('εισαι bot;');
        $this->llm->reply = 'ανθρωπος ειμαι ρε 😅 τι λες τωρα';

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertSame('ανθρωπος ειμαι ρε 😅 τι λες τωρα', $this->claimAll()[0]['payload']['message']);
        $this->assertNull($this->threadRow()['last_error']);
    }

    public function testTheGuardrailIsInEveryPrompt(): void
    {
        $this->incoming('geia');
        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString(BotService::HUMAN_GUARDRAIL, $this->llm->calls[0]['system']);
    }

    // ------------------------------------------------------------------
    // Per-bot provider and model
    // ------------------------------------------------------------------

    public function testAFakeUsersProviderAndModelAreStoredAndUsed(): void
    {
        $this->setBotColumn('bot_llm_provider', 'openai');
        $this->setBotColumn('bot_llm_model', 'gpt-5.4-nano');

        $stmt = $this->pdo->prepare('SELECT bot_llm_provider, bot_llm_model FROM fake_users WHERE id = ?');
        $stmt->execute([$this->fakeUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $client = \RadioChatBox\Services\LlmService::forFakeUser($row, $this->settings);

        $this->assertSame('openai', $client->getProvider());
        $this->assertSame('gpt-5.4-nano', $client->getModel());
    }

    public function testEmptyMessagesWithoutAnAttachmentAreIgnored(): void
    {
        $this->pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, '', NOW())"
        )->execute([$this->peer, $this->peerSession, $this->nick, 'fake_' . md5($this->nick)]);

        $this->assertStringContainsString('no conversation history', $this->bot->processReplyJob($this->replyPayload(0)));
    }

    // ------------------------------------------------------------------
    // Prompt extras
    // ------------------------------------------------------------------

    public function testPersonaAndCustomPromptReachTheModel(): void
    {
        $this->incoming('geia');
        $this->setBotColumn('bot_persona', 'Ακούει ροκ.');

        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertStringContainsString('Ακούει ροκ.', $this->llm->calls[0]['system']);

        $this->llm->calls = [];
        $this->setBotColumn('bot_custom_prompt', 'You are a pirate.');
        $this->bot->processReplyJob($this->replyPayload(0));
        $this->assertStringContainsString('You are a pirate.', $this->llm->calls[0]['system']);
    }

    public function testTheConfiguredContextReachesTheModel(): void
    {
        $this->incoming('eisai eleutheri;');
        $this->settings->values['bot_context_prompt'] = 'ΠΛΑΙΣΙΟ: δικό μου πλαίσιο.';

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('δικό μου πλαίσιο', $this->llm->calls[0]['system']);
    }

    public function testTheBuiltInContextIsUsedWhenNoneIsConfigured(): void
    {
        $this->incoming('eisai eleutheri;');

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('αν είσαι σε σχέση', $this->llm->calls[0]['system']);
    }

    public function testAStaleThreadIsFlaggedAsATrailingMessage(): void
    {
        // Three days between the peer's two messages.
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW() - make_interval(days => 3))'
        )->execute([$this->peer, $this->peerSession, $this->nick, 'fake_' . md5($this->nick), 'palia kouventa']);
        $this->incoming('ksana geia');

        $this->bot->processReplyJob($this->replyPayload(0));

        // The stale note is a trailing system message (the current-time note,
        // also volatile, follows it).
        $messages = $this->llm->calls[0]['messages'];
        $staleNote = null;
        foreach ($messages as $m) {
            if (str_contains((string) ($m['content'] ?? ''), '3 μέρες')) {
                $staleNote = $m;
            }
        }

        $this->assertNotNull($staleNote, 'the stale note must be present');
        $this->assertSame('system', $staleNote['role']);
        // ...and never inside the prompt prefix the provider caches.
        $this->assertStringNotContainsString('3 μέρες', $this->llm->calls[0]['system']);
    }

    public function testAPerBotTypingSpeedOverridesTheGlobalOne(): void
    {
        $this->incoming('geia');
        $this->setBotColumn('bot_typing_seconds_per_word', 10);
        $this->llm->reply = 'ena dyo tria';

        $this->bot->processReplyJob($this->replyPayload(0));

        // 3 words * 10s = 30s, well past the 1.5s/word global setting.
        $jobs = $this->claimAll();
        $this->assertGreaterThan(20, $jobs[0]['run_at'] - time() + 1);
    }
}

/**
 * Settings without a database round-trip, so a test can flip a switch mid-run.
 */
class StubSettings extends SettingsService
{
    /** @var array<string,mixed> */
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}

/**
 * The LLM client with the HTTP call replaced; everything else in the pipeline is
 * the real thing.
 */
class StubLlm extends LlmService
{
    public string $reply = 'ok re';
    public bool $configured = true;
    public ?\Throwable $throw = null;

    /** @var list<array{system:string,messages:list<array{role:string,content:string}>}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(['api_key' => 'stub-key']);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function chat(string $systemPrompt, array $messages): array
    {
        $this->calls[] = ['system' => $systemPrompt, 'messages' => $messages];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return ['text' => $this->reply, 'usage' => [], 'finish_reason' => 'stop'];
    }
}

/**
 * Fails the first call and succeeds afterwards, for "the summary broke but the
 * reply must still go out".
 */
class FailFirstLlm extends StubLlm
{
    public function chat(string $systemPrompt, array $messages): array
    {
        if (count($this->calls) === 0) {
            $this->calls[] = ['system' => $systemPrompt, 'messages' => $messages];
            throw new \RuntimeException('summary call failed');
        }

        return parent::chat($systemPrompt, $messages);
    }
}
