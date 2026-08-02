<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\MessageFilter;
use RadioChatBox\Services\SettingsService;

/**
 * The custom profanity filter: off (no-op), mask (star out the words) and block
 * (reject the whole public message). Word matching is boundary-aware and
 * Unicode-aware (so Greek terms match), and DMs downgrade block to masking.
 */
class ProfanityFilterTest extends TestCase
{
    private function configure(string $mode, string $words): void
    {
        $s = new SettingsService();
        $s->set('profanity_filter_mode', $mode);
        $s->set('profanity_words', $words);
        $s->invalidateCache();
        MessageFilter::resetCaches();
    }

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        $pdo->prepare("DELETE FROM settings WHERE setting IN ('profanity_filter_mode', 'profanity_words')")->execute();
        (new SettingsService())->invalidateCache();
        MessageFilter::resetCaches();
    }

    /** Off: the message is untouched by the profanity filter. */
    public function testOffLeavesMessageAlone(): void
    {
        $this->configure('off', 'badword');
        $result = MessageFilter::filterPublicMessage('this is a badword here');
        $this->assertTrue($result['allowed']);
        $this->assertStringContainsString('badword', $result['filtered']);
    }

    /** Mask: the listed word is starred out, message still allowed. */
    public function testMaskStarsOutWords(): void
    {
        $this->configure('mask', "badword\nrude");
        $result = MessageFilter::filterPublicMessage('you badword and rude person');
        $this->assertTrue($result['allowed']);
        $this->assertStringNotContainsString('badword', $result['filtered']);
        $this->assertStringContainsString('*******', $result['filtered']); // 7 chars
        $this->assertStringNotContainsString('rude', $result['filtered']);
    }

    /** Mask only hits whole words, not substrings. */
    public function testMaskIsWordBounded(): void
    {
        $this->configure('mask', 'ass');
        $result = MessageFilter::filterPublicMessage('the class assignment');
        // 'class' and 'assignment' contain "ass" but must NOT be masked.
        $this->assertStringContainsString('class', $result['filtered']);
        $this->assertStringContainsString('assignment', $result['filtered']);
    }

    /** Mask works on Greek words too (Unicode-aware boundaries). */
    public function testMaskHandlesGreek(): void
    {
        $this->configure('mask', 'μαλάκας');
        $result = MessageFilter::filterPublicMessage('είσαι μαλάκας ρε');
        $this->assertStringNotContainsString('μαλάκας', $result['filtered']);
        $this->assertStringContainsString('ρε', $result['filtered']);
    }

    /** Block: a public message containing a listed word is not allowed. */
    public function testBlockRejectsPublicMessage(): void
    {
        $this->configure('block', 'forbidden');
        $result = MessageFilter::filterPublicMessage('this is forbidden content');
        $this->assertFalse($result['allowed']);
        $this->assertNotSame('', $result['reason']);
    }

    /** Block does not reject a clean message. */
    public function testBlockAllowsCleanMessage(): void
    {
        $this->configure('block', 'forbidden');
        $result = MessageFilter::filterPublicMessage('this is perfectly fine');
        $this->assertTrue($result['allowed']);
    }

    /** In DMs, block is downgraded to masking (never silently dropped). */
    public function testPrivateMessageMasksInsteadOfBlocking(): void
    {
        $this->configure('block', 'forbidden');
        $result = MessageFilter::filterPrivateMessage('this is forbidden here');
        $this->assertTrue($result['allowed']);
        $this->assertStringNotContainsString('forbidden', $result['filtered']);
    }
}
