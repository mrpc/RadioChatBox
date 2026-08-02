<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\ModeratorCommandService;

/**
 * In-chat moderator commands (/mute, /unmute, /warn, /ban): only a moderator+
 * may run them (verified against the presence session), the target is affected
 * through the real services (timeout, warnings, nickname bans), and non-commands
 * fall through as normal chat.
 */
class ModeratorCommandServiceTest extends TestCase
{
    private PDO $pdo;
    private string $mod;      // moderator actor
    private string $modSess;
    private string $target;   // the user being moderated
    private string $targetSess;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->mod = 'mod_' . $suffix;
        $this->modSess = 'msess_' . $suffix;
        $this->target = 'trg_' . $suffix;
        $this->targetSess = 'tsess_' . $suffix;

        // A moderator (usertype 50) whose presence session is linked to the user row.
        $this->pdo->prepare('INSERT INTO users (username, password, usertype) VALUES (?, ?, 50)')
            ->execute([$this->mod, 'x']);
        $modId = (int) $this->pdo->query(
            'SELECT userid FROM users WHERE username = ' . $this->pdo->quote($this->mod)
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$this->mod, $this->modSess, $modId, '127.0.0.1']);

        // The target: a plain guest presence session, no role.
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->target, $this->targetSess, '127.0.0.2']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username IN (?, ?)')
            ->execute([$this->mod, $this->target]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->mod]);
        $this->pdo->prepare('DELETE FROM user_warnings WHERE username = ?')->execute([$this->target]);
        $this->pdo->prepare('DELETE FROM banned_nicknames WHERE LOWER(nickname) = LOWER(?)')->execute([$this->target]);
        $this->pdo->prepare('DELETE FROM moderation_log WHERE target = ?')->execute([$this->target]);
        FlatCache::default()->clear();
    }

    /** looksLikeCommand recognises our verbs and ignores everything else. */
    public function testLooksLikeCommand(): void
    {
        $this->assertTrue(ModeratorCommandService::looksLikeCommand('/mute someone 5'));
        $this->assertTrue(ModeratorCommandService::looksLikeCommand('/warn bob'));
        $this->assertFalse(ModeratorCommandService::looksLikeCommand('hello /mute'));
        $this->assertFalse(ModeratorCommandService::looksLikeCommand('/poll a | b | c'));
        $this->assertFalse(ModeratorCommandService::looksLikeCommand('just a message'));
    }

    /** A non-moderator is refused and nothing happens to the target. */
    public function testNonModeratorRefused(): void
    {
        // Act as the (role-less) target trying to mute the moderator.
        $reply = (new ModeratorCommandService())
            ->handle($this->target, $this->targetSess, '/mute ' . $this->mod . ' 10');
        $this->assertStringContainsStringIgnoringCase('staff only', $reply);
        $this->assertSame(0, (new ChatService())->getTimeoutRemaining($this->mod));
    }

    /** /mute times the target out for the requested minutes. */
    public function testMuteTimesOutTarget(): void
    {
        $reply = (new ModeratorCommandService())
            ->handle($this->mod, $this->modSess, '/mute @' . $this->target . ' 3');
        $this->assertStringContainsStringIgnoringCase('muted', $reply);
        $remaining = (new ChatService())->getTimeoutRemaining($this->target);
        $this->assertGreaterThan(150, $remaining); // ~180s, allow slack
        $this->assertLessThanOrEqual(180, $remaining);
    }

    /** /mute with no minutes uses the 5-minute default. */
    public function testMuteDefaultsToFiveMinutes(): void
    {
        (new ModeratorCommandService())->handle($this->mod, $this->modSess, '/mute ' . $this->target);
        $remaining = (new ChatService())->getTimeoutRemaining($this->target);
        $this->assertGreaterThan(250, $remaining);
        $this->assertLessThanOrEqual(300, $remaining);
    }

    /** /unmute lifts the mute immediately. */
    public function testUnmuteLiftsTheMute(): void
    {
        $chat = new ChatService();
        $chat->timeoutUser($this->target, 600);
        $this->assertGreaterThan(0, $chat->getTimeoutRemaining($this->target));

        (new ModeratorCommandService())->handle($this->mod, $this->modSess, '/unmute ' . $this->target);
        $this->assertSame(0, $chat->getTimeoutRemaining($this->target));
    }

    /** /warn records a warning row against the target. */
    public function testWarnRecordsWarning(): void
    {
        $reply = (new ModeratorCommandService())
            ->handle($this->mod, $this->modSess, '/warn ' . $this->target . ' spamming links');
        $this->assertStringContainsStringIgnoringCase('warned', $reply);
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM user_warnings WHERE username = ' . $this->pdo->quote($this->target)
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    /** /ban adds the nickname to the ban list. */
    public function testBanBansTheNickname(): void
    {
        $reply = (new ModeratorCommandService())
            ->handle($this->mod, $this->modSess, '/ban ' . $this->target . ' abuse');
        $this->assertStringContainsStringIgnoringCase('banned', $reply);
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM banned_nicknames WHERE LOWER(nickname) = LOWER(' . $this->pdo->quote($this->target) . ')'
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    /** A moderator can't mute themselves. */
    public function testCannotTargetSelf(): void
    {
        $reply = (new ModeratorCommandService())
            ->handle($this->mod, $this->modSess, '/mute ' . $this->mod);
        $this->assertStringContainsStringIgnoringCase("can't mute yourself", $reply);
    }

    /** A missing target returns a usage hint. */
    public function testMissingTargetShowsUsage(): void
    {
        $reply = (new ModeratorCommandService())->handle($this->mod, $this->modSess, '/warn');
        $this->assertStringContainsStringIgnoringCase('usage', $reply);
    }

    /** A message that isn't a command returns null (falls through as chat). */
    public function testNonCommandFallsThrough(): void
    {
        $this->assertNull(
            (new ModeratorCommandService())->handle($this->mod, $this->modSess, 'good morning everyone')
        );
    }
}
