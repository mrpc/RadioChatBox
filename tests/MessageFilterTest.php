<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\MessageFilter;

class MessageFilterTest extends TestCase
{
    public function testFilterPublicMessageRemovesUrls()
    {
        $message = 'Check out this link: https://example.com';
        $result = MessageFilter::filterPublicMessage($message);
        
        // URLs are replaced with ***
        $this->assertStringContainsString('***', $result['filtered']);
        $this->assertStringNotContainsString('https://example.com', $result['filtered']);
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
