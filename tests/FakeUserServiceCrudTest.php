<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\FakeUserService;
use RadioChatBox\Services\SettingsService;

/**
 * Covers the FakeUserService CRUD lifecycle (add → read → update → bot settings →
 * toggle → delete), which the export/import/balance tests do not exercise. The
 * fake user is tagged with a per-run suffix and removed in tearDown.
 */
class FakeUserServiceCrudTest extends TestCase
{
    private FakeUserService $service;
    private string $nick;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FakeUserService();
        $this->nick = 'fu' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        TestDatabase::connection()
            ->prepare('DELETE FROM fake_users WHERE nickname LIKE ?')
            ->execute(['%' . substr($this->nick, 2) . '%']);
        parent::tearDown();
    }

    /** The full add → read → update → bot-settings → toggle → delete lifecycle. */
    public function testFakeUserLifecycle(): void
    {
        $add = $this->service->addFakeUser($this->nick, 25, 'female', 'NYC');
        $this->assertIsArray($add);

        $row = $this->service->getFakeUserByNickname($this->nick);
        $this->assertNotNull($row);
        $id = (int) $row['id'];

        $this->assertNotNull($this->service->getFakeUserById($id));
        $this->assertContains($this->nick, array_column($this->service->getAllFakeUsers(), 'nickname'));
        $this->assertIsArray($this->service->getActiveFakeUsers());

        $this->assertNotNull($this->service->updateFakeUser($id, ['age' => 30, 'location' => 'LA']));
        $this->assertNotNull($this->service->updateBotSettings($id, [
            'bot_enabled'     => true,
            'bot_persona'     => 'friendly',
            'bot_max_messages' => 4,
        ]));

        $this->assertIsArray($this->service->toggleFakeUser($id));
        $this->assertTrue($this->service->setFakeUserActive($id, true));
        $this->assertIsArray($this->service->exportFakeUsers());

        $this->assertTrue($this->service->deleteFakeUser($id));
        $this->assertNull($this->service->getFakeUserById($id));
    }

    /**
     * importFakeUsers classifies each row: a valid new nickname is imported (with
     * its bot_* settings), a too-short nickname and an out-of-range age are
     * invalid, a second pass without updateExisting skips the now-existing row,
     * and a pass with updateExisting overwrites it. Covers the import branches and
     * normalizeProfileForImport's validation.
     */
    public function testImportFakeUsersClassifiesRows(): void
    {
        $frag  = substr($this->nick, 2);
        $good  = 'imp' . $frag;
        $short = 'ab';

        $rows = [
            ['nickname' => $good, 'age' => 22, 'sex' => 'male', 'location' => 'LA', 'bot_enabled' => true],
            ['nickname' => $short, 'age' => 20],                    // too short
            ['nickname' => 'bad' . $frag, 'age' => 5],              // age out of range
            'not-an-object',                                        // not an array
        ];

        $result = $this->service->importFakeUsers($rows, false);
        $this->assertContains($good, $result['imported']);
        $this->assertContains($short, array_column($result['invalid'], 'nickname'));
        $this->assertContains('bad' . $frag, array_column($result['invalid'], 'nickname'));
        // The bare string row is rejected with a null nickname ("Entry is not an object").
        $this->assertContains(null, array_column($result['invalid'], 'nickname'));

        // Second import of the same nickname without updateExisting: skipped.
        $skip = $this->service->importFakeUsers([['nickname' => $good, 'age' => 30]], false);
        $this->assertContains($good, $skip['skipped']);

        // With updateExisting: updated in place.
        $upd = $this->service->importFakeUsers([['nickname' => $good, 'age' => 40, 'sex' => 'female']], true);
        $this->assertContains($good, $upd['updated']);
        $this->assertSame(40, (int) $this->service->getFakeUserByNickname($good)['age']);
    }

    /**
     * updateFakeUser renaming a fake user rewrites its identity across
     * conversations (renameInConversations) and returns the new nickname.
     */
    public function testUpdateFakeUserRenames(): void
    {
        $add    = $this->service->addFakeUser($this->nick, 25, 'male', 'NYC');
        $id     = (int) $add['id'];
        $newNick = 'ren' . substr($this->nick, 2);

        $updated = $this->service->updateFakeUser($id, ['nickname' => $newNick]);
        $this->assertNotNull($updated);
        $this->assertSame($newNick, $updated['nickname']);
        $this->assertNotNull($this->service->getFakeUserByNickname($newNick));
    }

    /**
     * updateFakeUser rejects invalid profile values and collisions with the exact
     * legacy messages: too-short nickname, out-of-range age, invalid sex, and a
     * nickname already used by another fake user.
     */
    public function testUpdateFakeUserValidationThrows(): void
    {
        $id = (int) $this->service->addFakeUser($this->nick, 25, 'male', 'NYC')['id'];

        $cases = [
            [['nickname' => 'ab'], 'Nickname must be between 3 and 50 characters'],
            [['age' => 5], 'Age must be between 18 and 99'],
            [['sex' => 'nope'], 'Sex must be male, female or other'],
        ];
        foreach ($cases as [$fields, $message]) {
            try {
                $this->service->updateFakeUser($id, $fields);
                $this->fail("expected InvalidArgumentException for " . json_encode($fields));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        // Colliding with another fake user's nickname.
        $other = 'oth' . substr($this->nick, 2);
        $this->service->addFakeUser($other, 30, 'female', 'LA');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nickname already exists');
        $this->service->updateFakeUser($id, ['nickname' => $other]);
    }

    /** updateFakeUser on an unknown id returns null (no row). */
    public function testUpdateFakeUserUnknownIdReturnsNull(): void
    {
        $this->assertNull($this->service->updateFakeUser(0, ['age' => 30]));
    }

    /**
     * updateBotSettings normalises each field: bool cast, 0-100 clamping, unknown
     * provider/language reset to null (fall back to global), empty string to null,
     * and an empty option set returns the current row unchanged.
     */
    public function testUpdateBotSettingsNormalization(): void
    {
        $id = (int) $this->service->addFakeUser($this->nick, 25, 'male', 'NYC')['id'];

        $updated = $this->service->updateBotSettings($id, [
            'bot_enabled'                 => '1',                 // -> true
            'bot_max_messages'            => 500,                 // clamp -> 100
            'bot_ignore_chance'           => -5,                  // clamp -> 0
            'bot_typing_seconds_per_word' => 99,                  // clamp -> "10"
            'bot_llm_provider'            => 'no_such_provider',  // unknown -> null
            'bot_reply_language'          => 'zz',                // unknown -> null
            'bot_persona'                 => '',                  // empty -> null
        ]);

        $this->assertNotNull($updated);
        $this->assertTrue((bool) $updated['bot_enabled']);
        $this->assertSame(100, (int) $updated['bot_max_messages']);
        $this->assertSame(0, (int) $updated['bot_ignore_chance']);
        $this->assertNull($updated['bot_llm_provider']);
        $this->assertNull($updated['bot_reply_language']);
        $this->assertNull($updated['bot_persona']);

        // An empty option set is a no-op that returns the current row.
        $this->assertNotNull($this->service->updateBotSettings($id, []));
    }

    /**
     * balanceFakeUsers activates inactive fake users to meet the configured
     * minimum (no live radio URL → the minimum_users path), then deactivates the
     * excess when the minimum drops. Drives activateRandomFakeUsers /
     * deactivateRandomFakeUsers / getJitteredTarget / countActiveFakeUsers — safe
     * now that the suite runs against an isolated database. Settings are restored.
     */
    public function testBalanceActivatesAndDeactivatesFakeUsers(): void
    {
        $frag     = substr($this->nick, 2);
        $settings = new SettingsService();
        $prevMin  = (string) $settings->get('minimum_users', '0');
        $prevUrl  = (string) $settings->get('radio_status_url', '');

        // Seed several inactive fake users under this run's fragment.
        for ($i = 0; $i < 6; $i++) {
            $row = $this->service->addFakeUser('bal' . $i . $frag, 25, 'female', 'NYC');
            $this->service->setFakeUserActive((int) $row['id'], false);
        }

        try {
            $settings->setMultiple(['radio_status_url' => '', 'minimum_users' => '4']);
            FlatCache::default()->delete('fake_users:jitter_target');

            $this->service->balanceFakeUsers(0);
            $activeAfterRaise = count($this->service->getActiveFakeUsers());
            $this->assertGreaterThan(0, $activeAfterRaise, 'some fake users must be activated to meet the minimum');

            // Drop the minimum to zero → the balancer deactivates all fake users.
            $settings->setMultiple(['minimum_users' => '0']);
            FlatCache::default()->delete('fake_users:jitter_target');
            $this->service->balanceFakeUsers(0);
            $this->assertLessThanOrEqual(
                $activeAfterRaise,
                count($this->service->getActiveFakeUsers()),
                'lowering the minimum must not increase the active count'
            );
        } finally {
            $settings->setMultiple(['minimum_users' => $prevMin, 'radio_status_url' => $prevUrl]);
        }
    }
}
