<?php

namespace RadioChatBox\Controllers;

use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\UserService;

/**
 * Admin user management — migrated from the legacy per-file endpoints under
 * public/api/admin/. Every route carries AdminAuthMiddleware, which returns
 * 401 {"error":"Unauthorized"} for unauthenticated requests (replacing the
 * legacy AdminAuth::verify()/authenticate() + unauthorized() dance). The extra
 * RBAC the legacy performed beyond mere authentication —
 * AdminAuth::requirePermission('manage_users') and per-action
 * AdminAuth::hasPermission(...) / getCurrentUser() checks — is preserved inside
 * each action, returning the same status codes and error strings.
 *
 * Replaces:
 *   - public/api/admin/create-user.php  -> POST   /api/admin/create-user
 *   - public/api/admin/update-user.php  -> POST   /api/admin/update-user
 *   - public/api/admin/delete-user.php  -> DELETE /api/admin/delete-user
 *   - public/api/admin/users.php        -> GET    /api/admin/users
 *   - public/api/admin/user-details.php -> GET    /api/admin/user-details
 *   - public/api/admin/current-user.php -> GET    /api/admin/current-user
 */
final class AdminUsersController
{
    /**
     * POST /api/admin/create-user — create an admin/staff user account
     * (replaces public/api/admin/create-user.php).
     *
     * Requires the manage_users permission; creating a root user additionally
     * requires create_root_users. Success returns the UserService::createUser
     * result payload with HTTP 201, failure the same payload with 400.
     */
    #[Route('/api/admin/create-user', methods: 'POST', name: 'admin.users.create', middleware: [AdminAuthMiddleware::class])]
    public function create(): Response
    {
        // Require user management permission (legacy AdminAuth::requirePermission).
        if (!AdminAuth::hasPermission('manage_users')) {
            return Response::json(['error' => 'Forbidden: Insufficient permissions'], 403);
        }

        // Get current user info.
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // The framework Request populates $_POST from the JSON body for POST.
        $input = $_POST;

        if (empty($input)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        // Validate required fields.
        if (empty($input['username']) || empty($input['password']) || empty($input['role'])) {
            return Response::json(['error' => 'Missing required fields: username, password, role'], 400);
        }

        $userService = new UserService();

        // Check if current user can create users with this role.
        if ($input['role'] === 'root' && !AdminAuth::hasPermission('create_root_users')) {
            return Response::json(['error' => 'Only root users can create other root users'], 403);
        }

        // Get current user ID for created_by field.
        $currentUserData = $userService->getUserByUsername($currentUser['username']);
        $createdBy = $currentUserData ? $currentUserData['id'] : null;

        $result = $userService->createUser(
            $input['username'],
            $input['password'],
            $input['role'],
            $input['email'] ?? null,
            $createdBy
        );

        if ($result['success']) {
            return Response::json($result, 201);
        }

        return Response::json($result, 400);
    }

    /**
     * POST /api/admin/update-user — update an existing user
     * (replaces public/api/admin/update-user.php).
     *
     * Requires manage_users; the caller must be able to manage the target's
     * role, and promoting to root requires create_root_users. Accepts any of
     * password/email/role/is_active/display_name. Success returns the
     * UserService::updateUser payload with 200, failure with 400.
     */
    #[Route('/api/admin/update-user', methods: 'POST', name: 'admin.users.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        if (!AdminAuth::hasPermission('manage_users')) {
            return Response::json(['error' => 'Forbidden: Insufficient permissions'], 403);
        }

        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // The framework Request populates $_POST from the JSON body for POST.
        $input = $_POST;

        if (empty($input)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        if (empty($input['user_id'])) {
            return Response::json(['error' => 'Missing required field: user_id'], 400);
        }

        $userId = (int) $input['user_id'];
        $userService = new UserService();

        // Get target user to check if current user can manage them.
        $targetUser = $userService->getUserById($userId);
        if (!$targetUser) {
            return Response::json(['error' => 'User not found'], 404);
        }

        // Check if current user can manage target user.
        if (!$userService->canManageUser($currentUser['role'], $targetUser['role'])) {
            return Response::json(['error' => 'You cannot manage this user'], 403);
        }

        // If updating role to root, check permission.
        if (isset($input['role']) && $input['role'] === 'root') {
            if (!AdminAuth::hasPermission('create_root_users')) {
                return Response::json(['error' => 'Only root users can assign root role'], 403);
            }
        }

        // Build updates array.
        $updates = [];
        if (isset($input['password'])) {
            $updates['password'] = $input['password'];
        }
        if (isset($input['email'])) {
            $updates['email'] = $input['email'];
        }
        if (isset($input['role'])) {
            $updates['role'] = $input['role'];
        }
        if (isset($input['is_active'])) {
            $updates['is_active'] = $input['is_active'];
        }
        if (isset($input['display_name'])) {
            $updates['display_name'] = $input['display_name'];
        }

        if (empty($updates)) {
            return Response::json(['error' => 'No fields to update'], 400);
        }

        $result = $userService->updateUser($userId, $updates);

        if ($result['success']) {
            return Response::json($result, 200);
        }

        return Response::json($result, 400);
    }

    /**
     * DELETE /api/admin/delete-user — delete a user
     * (replaces public/api/admin/delete-user.php).
     *
     * Requires manage_users; you cannot delete your own account, must be able
     * to manage the target's role, and deleting a root user requires
     * delete_root_users. Success returns the UserService::deleteUser payload
     * with 200, failure with 400.
     *
     * Note: this is a genuine DELETE request carrying a JSON body. The framework
     * only json-decodes bodies into $_POST for POST requests — for DELETE it
     * runs parse_str into its internal delete buffer, which garbles a JSON body
     * and leaves $_POST empty. So we prefer $_POST (in case of a form/POST
     * caller) and fall back to decoding the raw JSON body ourselves, replicating
     * the legacy json_decode(file_get_contents('php://input')) exactly.
     */
    #[Route('/api/admin/delete-user', methods: 'DELETE', name: 'admin.users.delete', middleware: [AdminAuthMiddleware::class])]
    public function delete(): Response
    {
        if (!AdminAuth::hasPermission('manage_users')) {
            return Response::json(['error' => 'Forbidden: Insufficient permissions'], 403);
        }

        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // Read the request body. DELETE bodies are not decoded into $_POST by the
        // framework, so fall back to the raw JSON body (as the legacy file did).
        $input = $_POST;
        if (empty($input)) {
            $raw = file_get_contents('php://input');
            $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        if (empty($input)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        if (empty($input['user_id'])) {
            return Response::json(['error' => 'Missing required field: user_id'], 400);
        }

        $userId = (int) $input['user_id'];
        $userService = new UserService();

        // Get target user to check if current user can manage them.
        $targetUser = $userService->getUserById($userId);
        if (!$targetUser) {
            return Response::json(['error' => 'User not found'], 404);
        }

        // Prevent self-deletion.
        $currentUserData = $userService->getUserByUsername($currentUser['username']);
        if ($currentUserData && $currentUserData['id'] === $userId) {
            return Response::json(['error' => 'Cannot delete your own account'], 400);
        }

        // Check if current user can manage target user.
        if (!$userService->canManageUser($currentUser['role'], $targetUser['role'])) {
            return Response::json(['error' => 'You cannot delete this user'], 403);
        }

        // For root users, require explicit permission.
        if ($targetUser['role'] === 'root' && !AdminAuth::hasPermission('delete_root_users')) {
            return Response::json(['error' => 'Only root users can delete other root users'], 403);
        }

        $result = $userService->deleteUser($userId);

        if ($result['success']) {
            return Response::json($result, 200);
        }

        return Response::json($result, 400);
    }

    /**
     * GET /api/admin/users — list all admin/staff users
     * (replaces public/api/admin/users.php).
     *
     * Requires manage_users. Pass ?include_inactive=true to include inactive
     * accounts. Returns {success:true, users:[...], count:int} with 200.
     */
    #[Route('/api/admin/users', methods: 'GET', name: 'admin.users.list', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        if (!AdminAuth::hasPermission('manage_users')) {
            return Response::json(['error' => 'Forbidden: Insufficient permissions'], 403);
        }

        $userService = new UserService();

        $includeInactive = Request::getInstance()->get('include_inactive', '', 'get') === 'true';

        $users = $userService->getAllUsers($includeInactive);

        return Response::json([
            'success' => true,
            'users'   => $users,
            'count'   => count($users),
        ]);
    }

    /**
     * GET /api/admin/user-details — full activity dossier for a chat username
     * (replaces public/api/admin/user-details.php).
     *
     * Authenticated admins only (no manage_users gate). Reads the user's
     * profile, paginated public messages (with optional ?search), known IP
     * addresses, active session and — for holders of view_private_messages —
     * paginated private messages. Returns {success, user{...}, pagination{...},
     * search}. Missing ?username -> 400; DB errors -> 500 "Server error: ...".
     */
    #[Route('/api/admin/user-details', methods: 'GET', name: 'admin.users.details', middleware: [AdminAuthMiddleware::class])]
    public function details(): Response
    {
        $db = Database::getPDO();

        try {
            $request  = Request::getInstance();
            $username = $request->get('username', null, 'get');

            if (!$username) {
                return Response::json(['error' => 'Username parameter required'], 400);
            }

            // Pagination parameters.
            $page   = $request->get('page', null, 'get') !== null ? max(1, (int) $request->get('page', 1, 'get')) : 1;
            $limit  = $request->get('limit', null, 'get') !== null ? min((int) $request->get('limit', 50, 'get'), 500) : 50;
            $offset = ($page - 1) * $limit;

            // Search parameter.
            $search = (string) $request->get('search', '', 'get');

            // Get user profile.
            $stmt = $db->prepare("SELECT * FROM user_profiles WHERE username = :username ORDER BY created_at DESC LIMIT 1");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get total message count for this user (with search filter if provided).
            if (!empty($search)) {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM messages
                    WHERE username = :username AND message ILIKE :search
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM messages
                    WHERE username = :username
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
            }
            $totalMessages = (int) $stmt->fetchColumn();
            $totalPages = ceil($totalMessages / $limit);

            // Get user's messages with pagination and search.
            if (!empty($search)) {
                $stmt = $db->prepare("
                    SELECT m.*, u.ip_address
                    FROM messages m
                    LEFT JOIN user_activity u ON m.username = u.username
                    WHERE m.username = :username AND m.message ILIKE :search
                    ORDER BY m.created_at DESC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("
                    SELECT m.*, u.ip_address
                    FROM messages m
                    LEFT JOIN user_activity u ON m.username = u.username
                    WHERE m.username = :username
                    ORDER BY m.created_at DESC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
            }
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get user's IP addresses (from user_activity table).
            $stmt = $db->prepare("
                SELECT DISTINCT ip_address, first_seen
                FROM user_activity
                WHERE username = :username
                ORDER BY first_seen DESC
            ");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $ipAddresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get active session info.
            $stmt = $db->prepare("SELECT * FROM sessions WHERE username = :username");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get private messages count and paginated results (only for root and administrator).
            $privateMessages = [];
            $totalPrivateMessages = 0;
            $privateMessagesPages = 0;
            if (AdminAuth::hasPermission('view_private_messages')) {
                // Get total private messages count.
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM private_messages
                    WHERE from_username = :username OR to_username = :username
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                $totalPrivateMessages = (int) $stmt->fetchColumn();
                $privateMessagesPages = ceil($totalPrivateMessages / $limit);

                // Get paginated private messages.
                $stmt = $db->prepare("
                    SELECT * FROM private_messages
                    WHERE from_username = :username OR to_username = :username
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $privateMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return Response::json([
                'success' => true,
                'user' => [
                    'username' => $username,
                    'profile' => $profile ?: null,
                    'messages' => $messages,
                    'ip_addresses' => $ipAddresses,
                    'active_session' => $activeSession ?: null,
                    'private_messages' => $privateMessages,
                    'total_messages' => $totalMessages,
                    'total_private_messages' => $totalPrivateMessages
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_messages' => $totalMessages,
                    'total_pages' => $totalPages,
                    'total_private_messages' => $totalPrivateMessages,
                    'private_messages_pages' => $privateMessagesPages
                ],
                'search' => $search
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/current-user — the authenticated admin's own account
     * (replaces public/api/admin/current-user.php).
     *
     * Returns {success:true, user:{...}} with the full DB user row, falling
     * back to {username, role} from the session when no DB record exists
     * (legacy auth). 200 on success, 401 if the session resolves to no user.
     */
    #[Route('/api/admin/current-user', methods: 'GET', name: 'admin.users.current', middleware: [AdminAuthMiddleware::class])]
    public function current(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();

        if (!$currentUser) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // Get full user details from database.
        $userService = new UserService();
        $userDetails = $userService->getUserByUsername($currentUser['username']);

        if (!$userDetails) {
            // Fallback for legacy auth.
            $userDetails = [
                'username' => $currentUser['username'],
                'role' => $currentUser['role']
            ];
        }

        return Response::json([
            'success' => true,
            'user' => $userDetails
        ]);
    }
}
