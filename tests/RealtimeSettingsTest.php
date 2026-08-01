<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\RealtimeSettings;
use RadioChatBox\Services\SettingsService;

/**
 * The realtime WS config resolver: an admin setting is the source of truth (so it
 * is configurable from the panel), falling back to an env var, then a default.
 */
class RealtimeSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        $s = new SettingsService();
        foreach (['realtime_websocket_enabled', 'realtime_ws_public_host', 'realtime_app_key'] as $k) {
            $s->set($k, '');
        }
    }

    public function testEnabledIsSettingDrivenAndOffByDefault(): void
    {
        // `enabled` comes ONLY from the setting (env never enables it), so a clean
        // install is off → SSE. Other fields fall back to env/default, so we assert
        // only the well-formed shape here (env-agnostic).
        $c = RealtimeSettings::resolve();
        $this->assertFalse($c['enabled']);
        $this->assertIsInt($c['bindPort']);
        $this->assertNotSame('', $c['appKey']);
    }

    public function testSettingOverridesTakeEffect(): void
    {
        $s = new SettingsService();
        $s->set('realtime_websocket_enabled', 'true');
        $s->set('realtime_ws_public_host', 'chat.example.com');
        $s->set('realtime_app_key', 'my-key');

        $c = RealtimeSettings::resolve();
        $this->assertTrue($c['enabled']);
        $this->assertSame('chat.example.com', $c['publicHost']);
        $this->assertSame('my-key', $c['appKey']);
    }
}
