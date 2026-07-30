<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\UserService;

/**
 * Covers the UserService list/role helpers not exercised by the CRUD/auth tests:
 * getAllUsers (incl. inactive), getActiveRealUsers, the role catalogue and the
 * management/role-level edge cases.
 */
class UserServiceListTest extends TestCase
{
    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserService();
    }

    /** getAllUsers returns rows in both the active-only and include-inactive modes. */
    public function testGetAllUsersBothModes(): void
    {
        $this->assertIsArray($this->service->getAllUsers(false));
        $this->assertIsArray($this->service->getAllUsers(true));
    }

    /** getActiveRealUsers and the role catalogue return arrays. */
    public function testActiveRealUsersAndRoles(): void
    {
        $this->assertIsArray($this->service->getActiveRealUsers());
        $roles = $this->service->getAvailableRoles();
        $this->assertNotEmpty($roles);
    }

    /** canManageUser + getRoleLevel edge cases. */
    public function testManagementAndRoleLevelEdges(): void
    {
        $this->assertFalse($this->service->canManageUser('moderator', 'root'), 'a moderator cannot manage root');
        $this->assertTrue($this->service->canManageUser('root', 'administrator'), 'root manages everyone');
        $this->assertSame(0, $this->service->getRoleLevel('not-a-real-role'));
    }
}
