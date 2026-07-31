<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\Authz;

/**
 * Unit coverage for the Authz facade — the single source of truth for the
 * usertype↔role-label mapping and the permission decisions that replaced
 * UserService::PERMISSIONS / canManageUser after the schema convergence.
 */
class AuthzTest extends TestCase
{
    /** Label ⇄ usertype round-trips for every canonical role. */
    public function testLabelUsertypeRoundTrip(): void
    {
        foreach (['root' => 99, 'administrator' => 90, 'moderator' => 50, 'simple_user' => 0] as $label => $usertype) {
            $this->assertSame($usertype, Authz::usertypeForLabel($label));
            $this->assertSame($label, Authz::labelForUsertype($usertype));
        }
        // Unknown label → simple_user; intermediate usertype → nearest lower tier.
        $this->assertSame(0, Authz::usertypeForLabel('nope'));
        $this->assertSame('administrator', Authz::labelForUsertype(95));
        $this->assertSame('simple_user', Authz::labelForUsertype(10));
    }

    public function testAvailableRoleLabels(): void
    {
        $this->assertSame(
            ['simple_user', 'moderator', 'administrator', 'root'],
            Authz::availableRoleLabels()
        );
        $this->assertTrue(Authz::isValidLabel('root'));
        $this->assertFalse(Authz::isValidLabel('owner'));
    }

    /** can() reproduces the pre-convergence permission map exactly. */
    public function testCanReproducesPermissionMap(): void
    {
        // root-only
        $this->assertTrue(Authz::can(Authz::ROOT, 'create_root_users'));
        $this->assertFalse(Authz::can(Authz::ADMINISTRATOR, 'create_root_users'));
        // admin+ (not moderator)
        $this->assertTrue(Authz::can(Authz::ADMINISTRATOR, 'manage_settings'));
        $this->assertFalse(Authz::can(Authz::MODERATOR, 'manage_settings'));
        // moderator has view_bans; administrator does NOT (it has manage_bans)
        $this->assertTrue(Authz::can(Authz::MODERATOR, 'view_bans'));
        $this->assertFalse(Authz::can(Authz::ADMINISTRATOR, 'view_bans'));
        $this->assertTrue(Authz::can(Authz::ADMINISTRATOR, 'manage_bans'));
        // view_messages: moderator and up
        $this->assertTrue(Authz::can(Authz::MODERATOR, 'view_messages'));
        $this->assertFalse(Authz::can(Authz::SIMPLE_USER, 'view_messages'));
        // unknown permission → deny
        $this->assertFalse(Authz::can(Authz::ROOT, 'nonexistent'));
    }

    /** canManage() reproduces canManageUser(): root→all, admin→all but root. */
    public function testCanManageLadder(): void
    {
        $this->assertTrue(Authz::canManage(Authz::ROOT, Authz::ROOT));
        $this->assertTrue(Authz::canManage(Authz::ROOT, Authz::ADMINISTRATOR));
        $this->assertTrue(Authz::canManage(Authz::ADMINISTRATOR, Authz::ADMINISTRATOR));
        $this->assertTrue(Authz::canManage(Authz::ADMINISTRATOR, Authz::MODERATOR));
        $this->assertFalse(Authz::canManage(Authz::ADMINISTRATOR, Authz::ROOT));
        $this->assertFalse(Authz::canManage(Authz::MODERATOR, Authz::MODERATOR));
        $this->assertFalse(Authz::canManage(Authz::SIMPLE_USER, Authz::SIMPLE_USER));
    }
}
