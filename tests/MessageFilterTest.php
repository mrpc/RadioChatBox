<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use RadioChatBox\Services\MessageFilter;
use RadioChatBox\Services\SettingsService;

class MessageFilterTest extends TestCase
{
    /** The gif_enabled value present before the test, so tearDown can restore it. */
    private ?string $previousGifEnabled = null;

    /**
     * The GIF-preservation tests assert the feature is ON. That is gated by the
     * `gif_enabled` setting, which lives in the shared database and can be left
     * OFF by real admin use or other tests. To stay deterministic we force it on
     * here (snapshotting the prior value to restore in tearDown) and clear
     * MessageFilter's memoized flag so the change is picked up.
     */
    protected function setUp(): void
    {
        $pdo = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE setting = ?');
        $stmt->execute(['gif_enabled']);
        $value = $stmt->fetchColumn();
        $this->previousGifEnabled = $value === false ? null : (string) $value;

        $pdo->prepare(
            'INSERT INTO settings (setting, value) VALUES (?, ?)
             ON CONFLICT (setting) DO UPDATE SET value = EXCLUDED.value'
        )->execute(['gif_enabled', 'true']);

        (new SettingsService())->invalidateCache();
        MessageFilter::resetCaches();
    }

    /**
     * Restore gif_enabled to exactly what it was, so this test never leaks state
     * into the shared database (which is what made these tests flaky before).
     */
    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        if ($this->previousGifEnabled === null) {
            $pdo->prepare('DELETE FROM settings WHERE setting = ?')->execute(['gif_enabled']);
        } else {
            $pdo->prepare(
                'INSERT INTO settings (setting, value) VALUES (?, ?)
                 ON CONFLICT (setting) DO UPDATE SET value = EXCLUDED.value'
            )->execute(['gif_enabled', $this->previousGifEnabled]);
        }

        (new SettingsService())->invalidateCache();
        MessageFilter::resetCaches();
    }

    public function testFilterPublicMessageRemovesUrls()
    {
        $message = 'Check out this link: https://example.com';
        $result = MessageFilter::filterPublicMessage($message);
        
        // URLs are replaced with ***
        $this->assertStringContainsString('***', $result['filtered']);
        $this->assertStringNotContainsString('https://example.com', $result['filtered']);
    }
    
    /**
     * Every dangerous-content branch of checkDangerousContent must neutralise the
     * offending markup (replaced with ***) and flag the message as modified, on
     * both the public and the private filter paths.
     *
     */
    #[DataProvider('dangerousContentProvider')]
    public function testDangerousContentIsNeutralised(string $payload, string $mustNotContain): void
    {
        foreach (['filterPublicMessage', 'filterPrivateMessage'] as $method) {
            $result = MessageFilter::$method('hi ' . $payload);
            $this->assertTrue($result['modified'], "$method must flag '$payload' as modified");
            $this->assertStringNotContainsString($mustNotContain, $result['filtered']);
            $this->assertStringContainsString('***', $result['filtered']);
        }
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function dangerousContentProvider(): array
    {
        return [
            'script tag'      => ['<script>alert(1)</script>', '<script'],
            'event handler'   => ['<b onclick="x()">y</b>', 'onclick'],
            'javascript uri'  => ['javascript:alert(1)', 'javascript:'],
            'data html uri'   => ['data:text/html,<b>x</b>', 'data:text/html'],
            'style tag'       => ['<style>body{}</style>', '<style'],
            'iframe tag'      => ['<iframe src="x"></iframe>', '<iframe'],
            'meta tag'        => ['<meta http-equiv="refresh">', '<meta'],
            'base tag'        => ['<base href="x">', '<base'],
            'link tag'        => ['<link rel="stylesheet">', '<link'],
            'form tag'        => ['<form action="x">', '<form'],
            'form input'      => ['<input type="text">', '<input'],
        ];
    }

    /**
     * A phone number embedded in text is stripped by the public filter's
     * replacePhoneNumbers step.
     */
    public function testPhoneNumberIsRemoved(): void
    {
        $result = MessageFilter::filterPublicMessage('call me on 6971234567 please');
        $this->assertTrue($result['modified']);
        $this->assertStringNotContainsString('6971234567', $result['filtered']);
    }

    public function testFilterPublicMessageAllowsNormalText()
    {
        $message = 'Hello world! This is a normal message.';
        $result = MessageFilter::filterPublicMessage($message);
        
        $this->assertEquals($message, $result['filtered']);
    }
    
    public function testFilterPublicMessageDoesNotTruncate()
    {
        // The MessageFilter doesn't truncate - that's done at the API level
        $message = str_repeat('a', 600);
        $result = MessageFilter::filterPublicMessage($message);
        
        // Filter doesn't truncate, API does
        $this->assertEquals(600, mb_strlen($result['filtered']));
    }
    
    public function testFilterPrivateMessageAllowsUrls(): void
    {
        $message = 'Check out https://example.com for more info';
        $result = MessageFilter::filterPrivateMessage($message);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('filtered', $result);
        $this->assertStringContainsString('https://example.com', $result['filtered']);
    }
    
    public function testEscapeHtmlEntities()
    {
        $message = '<script>alert("xss")</script>';
        $result = MessageFilter::filterPublicMessage($message);
        
        // URLs/patterns are replaced, but HTML should be escaped too
        $this->assertStringNotContainsString('<script>', $result['filtered']);
    }
    
    public function testUrlFilteringWorks()
    {
        // Test that URL detection works (replaces with ***)
        $message = 'Visit https://example.com for more';
        $result = MessageFilter::filterPublicMessage($message);

        // Should contain *** replacement
        $this->assertStringContainsString('***', $result['filtered']);
        $this->assertStringNotContainsString('https://example.com', $result['filtered']);
    }

    /**
     * Three spam-URL violations from the same IP must auto-ban it by inserting a
     * banned_ips row. This pins the converted auto-ban path — the ban-existence
     * check (queryBuilder()->exists()) and the INSERT (queryBuilder()->insert())
     * that replaced the raw PDO statements. Uses a random blacklist pattern and
     * cleans up the shared DB/Redis state it touches.
     */
    public function testThreeSpamUrlViolationsAutoBanTheIp(): void
    {
        // The auto-ban emits a log line; allow that output under the strict-output
        // suite (the regex permits, but does not require, any output).
        $this->expectOutputRegex('/.*/s');

        $pdo    = TestDatabase::connection();
        $redis  = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        $prefix = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
        $ip     = '203.0.113.77';
        $pattern = 'spamdomain-' . bin2hex(random_bytes(4)) . '.example';

        // Seed a blacklist pattern; clear the pattern cache, the violation
        // counter and any prior ban so the run is deterministic.
        $pdo->prepare('INSERT INTO url_blacklist (pattern) VALUES (?)')->execute([$pattern]);
        $redis->del($prefix . 'url_blacklist_patterns');
        $redis->del($prefix . "violations:spam_url:{$ip}");
        $pdo->prepare('DELETE FROM banned_ips WHERE ip_address = ?')->execute([$ip]);

        try {
            $message = "check https://{$pattern}/path please";
            for ($i = 0; $i < 3; $i++) {
                MessageFilter::filterPrivateMessage($message, $ip);
            }

            $stmt = $pdo->prepare(
                'SELECT reason, banned_by FROM banned_ips
                 WHERE ip_address = ? AND (banned_until IS NULL OR banned_until > NOW())'
            );
            $stmt->execute([$ip]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->assertNotFalse($row, 'the IP must be auto-banned after 3 violations');
            $this->assertSame('system', $row['banned_by']);
            $this->assertStringContainsString('spam', strtolower((string) $row['reason']));
        } finally {
            $pdo->prepare('DELETE FROM banned_ips WHERE ip_address = ?')->execute([$ip]);
            $pdo->prepare('DELETE FROM url_blacklist WHERE pattern = ?')->execute([$pattern]);
            $redis->del($prefix . 'url_blacklist_patterns');
            $redis->del($prefix . "violations:spam_url:{$ip}");
        }
    }

    // ---------------------------------------------------------------------
    // GIF URL preservation
    //
    // GIF CDN URLs must survive the whole filtering pipeline untouched. Their
    // paths contain long digit runs (e.g. Klipy hex hashes) that the
    // phone-number filter would otherwise replace with ***, breaking the image.
    // Regression test for that bug.
    // ---------------------------------------------------------------------

    public function testKlipyGifUrlWithDigitHeavyHashIsPreserved()
    {
        // This exact URL used to break: the "171607374" run in the hash was
        // replaced with *** by the phone-number filter.
        $message = 'https://static.klipy.com/ii/d7aec6f6f171607374b2065c836f92f4/fc/8c/mqe4Uw9P.gif';
        $result = MessageFilter::filterPublicMessage($message);

        $this->assertEquals($message, $result['filtered']);
        $this->assertStringNotContainsString('***', $result['filtered']);
        $this->assertFalse($result['modified']);
    }

    public function testGiphyGifUrlIsPreserved()
    {
        $message = 'https://media0.giphy.com/media/l0HlBO3eyHh8123456/giphy.gif';
        $result = MessageFilter::filterPublicMessage($message);

        $this->assertEquals($message, $result['filtered']);
        $this->assertStringNotContainsString('***', $result['filtered']);
    }

    public function testTenorGifUrlIsPreserved()
    {
        $message = 'https://media.tenor.com/1234567890abc/example.gif';
        $result = MessageFilter::filterPublicMessage($message);

        $this->assertEquals($message, $result['filtered']);
        $this->assertStringNotContainsString('***', $result['filtered']);
    }

    public function testMultipleGifUrlsArePreserved()
    {
        $gif1 = 'https://static.klipy.com/ii/d7aec6f6f171607374b2065c836f92f4/fc/8c/mqe4Uw9P.gif';
        $gif2 = 'https://media4.giphy.com/media/abc9998887776665/giphy.gif';
        $message = "$gif1 $gif2";
        $result = MessageFilter::filterPublicMessage($message);

        $this->assertStringContainsString($gif1, $result['filtered']);
        $this->assertStringContainsString($gif2, $result['filtered']);
        $this->assertStringNotContainsString('***', $result['filtered']);
    }

    public function testGifUrlPreservedWhilePhoneNumberIsStillRemoved()
    {
        // The GIF URL must survive, but a real phone number in the same message
        // must still be stripped.
        $gif = 'https://static.klipy.com/ii/d7aec6f6f171607374b2065c836f92f4/fc/8c/mqe4Uw9P.gif';
        $message = "call me 555 123 4567 $gif";
        $result = MessageFilter::filterPublicMessage($message);

        // GIF intact
        $this->assertStringContainsString($gif, $result['filtered']);
        // Phone number stripped
        $this->assertStringNotContainsString('555 123 4567', $result['filtered']);
        $this->assertStringContainsString('***', $result['filtered']);
    }

    public function testGifUrlPreservedWhileNonGifUrlIsRemoved()
    {
        $gif = 'https://static.klipy.com/ii/d7aec6f6f171607374b2065c836f92f4/fc/8c/mqe4Uw9P.gif';
        $message = "look https://spam.example.com $gif";
        $result = MessageFilter::filterPublicMessage($message);

        // GIF intact, spam URL removed
        $this->assertStringContainsString($gif, $result['filtered']);
        $this->assertStringNotContainsString('spam.example.com', $result['filtered']);
    }

    public function testPhoneNumberStillRemovedWithoutGif()
    {
        // Regression guard: the phone filter must keep working for normal text.
        $message = 'ring me on 555 123 4567 please';
        $result = MessageFilter::filterPublicMessage($message);

        $this->assertStringNotContainsString('555 123 4567', $result['filtered']);
        $this->assertStringContainsString('***', $result['filtered']);
    }
}
