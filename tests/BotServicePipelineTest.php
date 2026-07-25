<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\BotService;
use RadioChatBox\Database;
use RadioChatBox\JobQueue;
use RadioChatBox\LlmService;
use RadioChatBox\SettingsService;

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
        $this->pdo = Database::getPDO();

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
        $this->pdo->prepare('DELETE FROM sessions WHERE username = ?')->execute([$this->peer]);
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
        $redis = Database::getRedis();
        $key = Database::getRedisPrefix() . $this->queue->getNamespace() . ':delayed';
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
        $key = Database::getRedisPrefix() . 'bot:epoch:' . $this->fakeUserId . ':' . md5($this->peer);
        $value = Database::getRedis()->get($key);

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

        // The user's message is the last turn the model sees.
        $messages = $this->llm->calls[0]['messages'];
        $this->assertSame('user', $messages[count($messages) - 1]['role']);
        $this->assertSame('ti kaneis;', $messages[count($messages) - 1]['content']);

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
        $this->incoming('kai meta;');
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
            'INSERT INTO sessions (username, session_id, ip_address, last_heartbeat) VALUES (?, ?, ?, NOW())'
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

        $this->assertSame(['user', 'assistant', 'user'], $roles);
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

    public function testTheConfiguredContextIsAddedToTheBuiltInOne(): void
    {
        $this->incoming('eisai eleutheri;');
        $this->settings->values['bot_context_prompt'] = 'Το site είναι για μεταλλάδες.';

        $this->bot->processReplyJob($this->replyPayload(0));

        $system = $this->llm->calls[0]['system'];
        $this->assertStringContainsString('μεταλλάδες', $system);
        $this->assertStringContainsString('αν είσαι σε σχέση', $system, 'the glossary must survive');
    }

    public function testTheBuiltInContextIsUsedWhenNoneIsConfigured(): void
    {
        $this->incoming('eisai eleutheri;');

        $this->bot->processReplyJob($this->replyPayload(0));

        $this->assertStringContainsString('αν είσαι σε σχέση', $this->llm->calls[0]['system']);
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
