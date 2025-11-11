<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\SettingsService;

class SettingsServiceTest extends TestCase
{
    private SettingsService $settingsService;
    
    protected function setUp(): void
    {
        $this->settingsService = new SettingsService();
    }

    public function testGetReturnsSettingValue()
    {
        // Test getting a known setting that exists in the database
        $value = $this->settingsService->get('page_title', 'default');
        $this->assertIsString($value);
    }

    public function testGetReturnsDefaultWhenKeyNotFound()
    {
        $default = 'my_default_value';
        $value = $this->settingsService->get('non_existent_key_xyz', $default);
        $this->assertEquals($default, $value);
    }

    public function testGetPublicSettingsExcludesSensitiveKeys()
    {
        $settings = $this->settingsService->getPublicSettings();
        
        $this->assertIsArray($settings);
        
        // Should not contain sensitive keys
        $this->assertArrayNotHasKey('admin_password_hash', $settings);
        
        // Should contain public keys if they exist
        if (isset($settings['page_title'])) {
            $this->assertIsString($settings['page_title']);
        }
    }

    public function testSetUpdatesSettingValue()
    {
        $testKey = 'test_setting_' . time();
        $testValue = 'test_value_' . rand(1000, 9999);
        
        $result = $this->settingsService->set($testKey, $testValue);
        $this->assertTrue($result);
        
        // Verify it was set
        $retrieved = $this->settingsService->get($testKey);
        $this->assertEquals($testValue, $retrieved);
        
        // Cleanup
        $this->settingsService->set($testKey, null);
    }

    public function testSetMultipleUpdatesMultipleSettings()
    {
        $testKey1 = 'test_multi_1_' . time();
        $testKey2 = 'test_multi_2_' . time();
        
        $settings = [
            $testKey1 => 'value1',
            $testKey2 => 'value2'
        ];
        
        $result = $this->settingsService->setMultiple($settings);
        $this->assertTrue($result);
        
        // Verify they were set
        $this->assertEquals('value1', $this->settingsService->get($testKey1));
        $this->assertEquals('value2', $this->settingsService->get($testKey2));
        
        // Cleanup
        $this->settingsService->set($testKey1, null);
        $this->settingsService->set($testKey2, null);
    }

    public function testGetSeoMetaReturnsCorrectStructure()
    {
        $seo = $this->settingsService->getSeoMeta();
        
        $this->assertIsArray($seo);
        $this->assertArrayHasKey('title', $seo);
        $this->assertArrayHasKey('description', $seo);
        $this->assertArrayHasKey('keywords', $seo);
        $this->assertArrayHasKey('og_image', $seo);
    }

    public function testGetBrandingReturnsCorrectStructure()
    {
        $branding = $this->settingsService->getBranding();
        
        $this->assertIsArray($branding);
        $this->assertArrayHasKey('logo_url', $branding);
        $this->assertArrayHasKey('favicon_url', $branding);
        $this->assertArrayHasKey('color', $branding);
        $this->assertArrayHasKey('name', $branding);
    }

    public function testGetAdSettingsReturnsCorrectStructure()
    {
        $ads = $this->settingsService->getAdSettings();
        
        $this->assertIsArray($ads);
        $this->assertArrayHasKey('enabled', $ads);
        $this->assertArrayHasKey('refresh_enabled', $ads);
        $this->assertArrayHasKey('refresh_interval', $ads);
    }

    public function testGetScriptsReturnsHeaderAndBodyScripts()
    {
        $scripts = $this->settingsService->getScripts();
        
        $this->assertIsArray($scripts);
        $this->assertArrayHasKey('header', $scripts);
        $this->assertArrayHasKey('body', $scripts);
    }

    public function testGetAnalyticsConfigReturnsCorrectStructure()
    {
        $analytics = $this->settingsService->getAnalyticsConfig();
        
        $this->assertIsArray($analytics);
        $this->assertArrayHasKey('enabled', $analytics);
        $this->assertArrayHasKey('provider', $analytics);
        $this->assertArrayHasKey('tracking_id', $analytics);
    }
}
