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
}
