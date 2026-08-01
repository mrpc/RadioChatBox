<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ConsoleCommands\RealtimeServe;

/**
 * The realtime worker's ingest routing: public channels keep the SSE stream's
 * event names (with the Redis key prefix stripped), and a private message is
 * fanned out ONLY to the two participants' per-user channels — the routing that
 * keeps DMs private over the shared WebSocket.
 */
class RealtimeServeRoutingTest extends TestCase
{
    private const PREFIX = 'radiochatbox:app:';

    public function testPublicUpdatesMapToEventByType(): void
    {
        $cases = [
            ['type' => 'clear'],           // -> clear
            ['type' => 'message_deleted'], // -> message_deleted
            ['type' => 'reaction'],        // -> reaction
            ['body' => 'hi'],              // no type -> message
        ];
        $expected = ['clear', 'message_deleted', 'reaction', 'message'];

        foreach ($cases as $i => $payload) {
            $routes = RealtimeServe::routeMessage(self::PREFIX, self::PREFIX . 'chat:updates', 'message', $payload);
            $this->assertCount(1, $routes);
            $this->assertSame('chat:updates', $routes[0][0], 'prefix must be stripped');
            $this->assertSame($expected[$i], $routes[0][1]);
        }
    }

    public function testAdminNotificationsRouteToAdminOnlyChannel(): void
    {
        $routes = RealtimeServe::routeMessage(
            self::PREFIX,
            self::PREFIX . 'chat:admin_notifications',
            'message',
            ['title' => 'New report']
        );
        $this->assertSame(
            [['private-admin-notifications', 'notification', ['title' => 'New report']]],
            $routes,
            'admin notifications go only to the admin-only private channel'
        );
    }

    public function testUserUpdatesMapToUsersEvent(): void
    {
        $routes = RealtimeServe::routeMessage(self::PREFIX, self::PREFIX . 'chat:user_updates', 'message', ['count' => 3]);
        $this->assertSame([['chat:user_updates', 'users', ['count' => 3]]], $routes);
    }

    public function testPrivateMessageFansOutToBothParticipantsOnly(): void
    {
        $dm = ['from_username' => 'alice', 'to_username' => 'bob', 'message' => 'secret'];
        $routes = RealtimeServe::routeMessage(self::PREFIX, self::PREFIX . 'chat:private_messages', 'message', $dm);

        $channels = array_map(static fn ($r) => $r[0], $routes);
        sort($channels);
        $this->assertSame(['private-pm-alice', 'private-pm-bob'], $channels, 'only the two participants');
        foreach ($routes as $r) {
            $this->assertSame('private', $r[1]);
            $this->assertSame($dm, $r[2]);
        }
    }

    public function testPrivateMessageToSelfEmitsOneChannel(): void
    {
        $dm = ['from_username' => 'alice', 'to_username' => 'alice', 'message' => 'note to self'];
        $routes = RealtimeServe::routeMessage(self::PREFIX, self::PREFIX . 'chat:private_messages', 'message', $dm);
        $this->assertSame([['private-pm-alice', 'private', $dm]], $routes, 'no duplicate channel when from === to');
    }

    public function testPrivateMessageWithMissingParticipantIsDropped(): void
    {
        $routes = RealtimeServe::routeMessage(self::PREFIX, self::PREFIX . 'chat:private_messages', 'message', ['body' => 'x']);
        $this->assertSame([], $routes, 'a DM with no participants goes nowhere — never broadcast wide');
    }
}
