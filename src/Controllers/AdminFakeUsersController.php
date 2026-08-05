<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use RadioChatBox\Http\Validate;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\BotService;
use RadioChatBox\Services\FakeUserService;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\AdminAuth;

/**
 * Admin fake-user management.
 *
 * Groups the migrated admin fake-user endpoints behind AdminAuthMiddleware
 * (an unauthenticated request gets a 401 before the action runs). Each action
 * reproduces its legacy file's inputs, JSON keys, status codes and error
 * mapping exactly.
 */
final class AdminFakeUsersController
{
    /**
     * Admin: GET /api/admin/list-fake-users — the full fake-user list.
     *
     * Migrated from public/api/admin/list-fake-users.php. Payload {success,
     * fake_users}.
     */
    #[Route('/api/admin/list-fake-users', methods: 'GET', name: 'admin.fake-users.list', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        try {
            $fakeUsers = (new FakeUserService())->getAllFakeUsers();

            return Response::json([
                'success'    => true,
                'fake_users' => $fakeUsers,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: GET /api/admin/fake-users — the full fake-user list under the
     * 'users' key (dashboard-compatible).
     *
     * Migrated from public/api/admin/fake-users.php. Payload {success, users}.
     */
    #[Route('/api/admin/fake-users', methods: 'GET', name: 'admin.fake-users.index', middleware: [AdminAuthMiddleware::class])]
    public function index(): Response
    {
        try {
            $fakeUsers = (new FakeUserService())->getAllFakeUsers();

            return Response::json([
                'success' => true,
                'users'   => $fakeUsers, // 'users' (not 'fake_users') for dashboard compatibility
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/add-fake-user — create a fake user.
     *
     * Migrated from public/api/admin/add-fake-user.php. Input (JSON body ->
     * $_POST): nickname (required, 3-50 chars), age (optional, 18-99), sex,
     * location. Success {success, fake_user}. Invalid input -> 400; a duplicate
     * nickname (unique constraint) -> 400 "Nickname already exists"; other DB
     * errors -> 500 "Database error"; anything else -> 500.
     */
    #[Route('/api/admin/add-fake-user', methods: 'POST', name: 'admin.fake-users.add', middleware: [AdminAuthMiddleware::class])]
    public function add(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            // nickname: 3-50 chars (length); age: optional, 18-99 (numeric even
            // as a form string, thanks to the framework Validator numeric fix).
            $error = Validate::check($input, [
                'nickname' => 'required|min:3|max:50',
                'age'      => 'nullable|integer|min:18|max:99',
            ], [
                'nickname.required' => 'Nickname is required',
                'nickname.min'      => 'Nickname must be at least 3 characters',
                'nickname.max'      => 'Nickname must be max 50 characters',
                'age.integer'       => 'Age must be between 18 and 99',
                'age.min'           => 'Age must be between 18 and 99',
                'age.max'           => 'Age must be between 18 and 99',
            ]);
            if ($error) {
                return $error;
            }

            $nickname = (string) $input['nickname'];
            $age      = isset($input['age']) && $input['age'] !== '' ? (int) $input['age'] : null;
            $sex      = $input['sex'] ?? null;
            $location = $input['location'] ?? null;

            $fakeUser = (new FakeUserService())->addFakeUser($nickname, $age, $sex, $location);

            return Response::json([
                'success'   => true,
                'fake_user' => $fakeUser,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\PDOException $e) {
            // Unique-constraint violation (duplicate nickname).
            if (strpos($e->getMessage(), 'unique') !== false) {
                return Response::json(['error' => 'Nickname already exists'], 400);
            }
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Database error'], 500);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/update-fake-user — update a fake user's profile.
     *
     * Migrated from public/api/admin/update-fake-user.php. Input: id (required)
     * plus any of nickname, age, sex, location. Renaming rewrites the nickname
     * in existing conversations. Success {success, fake_user}; unknown id ->
     * 404; invalid input / nothing to update -> 400.
     */
    #[Route('/api/admin/update-fake-user', methods: 'POST', name: 'admin.fake-users.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Fake user id is required');
            }

            $fields = [];
            foreach (['nickname', 'age', 'sex', 'location'] as $key) {
                if (array_key_exists($key, $input)) {
                    $fields[$key] = $input[$key];
                }
            }

            if (empty($fields)) {
                throw new InvalidArgumentException('Nothing to update');
            }

            $fakeUser = (new FakeUserService())->updateFakeUser($id, $fields);

            if ($fakeUser === null) {
                return Response::json(['error' => 'Fake user not found'], 404);
            }

            return Response::json([
                'success'   => true,
                'fake_user' => $fakeUser,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('update-fake-user error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/update-fake-user-bot — update a fake user's bot
     * (LLM auto-reply) configuration.
     *
     * Migrated from public/api/admin/update-fake-user-bot.php. Input: id
     * (required) plus any of the whitelisted bot_* fields. Prompt fields are
     * limited to 2000 chars, bot_farewell_messages to 4000. Success {success,
     * fake_user}; unknown id -> 404; invalid input -> 400.
     */
    #[Route('/api/admin/update-fake-user-bot', methods: 'POST', name: 'admin.fake-users.update-bot', middleware: [AdminAuthMiddleware::class])]
    public function updateBot(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Fake user id is required');
            }

            $allowed = [
                'bot_enabled',
                'bot_allow_explicit',
                'bot_persona',
                'bot_custom_prompt',
                'bot_context_prompt',
                'bot_self_facts',
                'bot_farewell_messages',
                'bot_max_messages',
                'bot_ignore_chance',
                'bot_typing_seconds_per_word',
                'bot_llm_provider',
                'bot_llm_model',
                'bot_reply_language',
            ];

            $options = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $input)) {
                    $options[$key] = $input[$key];
                }
            }

            // Keep prompts to a sane size - they are sent on every LLM request.
            foreach (['bot_persona', 'bot_custom_prompt', 'bot_self_facts'] as $key) {
                if (isset($options[$key]) && mb_strlen((string) $options[$key]) > 2000) {
                    throw new InvalidArgumentException('Prompt fields are limited to 2000 characters');
                }
            }

            if (isset($options['bot_farewell_messages']) && mb_strlen((string) $options['bot_farewell_messages']) > 4000) {
                throw new InvalidArgumentException('Goodbye variants are limited to 4000 characters in total');
            }

            // The context block is longer than the persona snippets but still bounded.
            if (isset($options['bot_context_prompt']) && mb_strlen((string) $options['bot_context_prompt']) > 4000) {
                throw new InvalidArgumentException('The context override is limited to 4000 characters');
            }

            $fakeUser = (new FakeUserService())->updateBotSettings($id, $options);

            if ($fakeUser === null) {
                return Response::json(['error' => 'Fake user not found'], 404);
            }

            return Response::json([
                'success'   => true,
                'fake_user' => $fakeUser,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('update-fake-user-bot error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST|DELETE /api/admin/delete-fake-user — delete a fake user.
     *
     * Migrated from public/api/admin/delete-fake-user.php. Input: id (required).
     * Success {success, message}; unknown id -> 404; invalid input -> 400.
     */
    #[Route('/api/admin/delete-fake-user', methods: ['DELETE', 'POST'], name: 'admin.fake-users.delete', middleware: [AdminAuthMiddleware::class])]
    public function delete(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $id = $input['id'] ?? 0;

            if (empty($id)) {
                throw new InvalidArgumentException('ID is required');
            }

            $success = (new FakeUserService())->deleteFakeUser($id);

            if (!$success) {
                return Response::json(['error' => 'Fake user not found'], 404);
            }

            return Response::json([
                'success' => true,
                'message' => 'Fake user deleted',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/toggle-fake-user — toggle a fake user active/idle.
     *
     * Migrated from public/api/admin/toggle-fake-user.php. Input: id (required).
     * Success {success, fake_user}; unknown id -> 404; invalid input -> 400.
     */
    #[Route('/api/admin/toggle-fake-user', methods: 'POST', name: 'admin.fake-users.toggle', middleware: [AdminAuthMiddleware::class])]
    public function toggle(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $id = $input['id'] ?? 0;

            if (empty($id)) {
                throw new InvalidArgumentException('ID is required');
            }

            $fakeUser = (new FakeUserService())->toggleFakeUser($id);

            if (!$fakeUser) {
                return Response::json(['error' => 'Fake user not found'], 404);
            }

            return Response::json([
                'success'   => true,
                'fake_user' => $fakeUser,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: GET /api/admin/export-fake-users — export all fake users.
     *
     * Migrated from public/api/admin/export-fake-users.php. Payload {success,
     * count, fake_users}.
     */
    #[Route('/api/admin/export-fake-users', methods: 'GET', name: 'admin.fake-users.export', middleware: [AdminAuthMiddleware::class])]
    public function export(): Response
    {
        try {
            $fakeUsers = (new FakeUserService())->exportFakeUsers();

            return Response::json([
                'success'    => true,
                'count'      => count($fakeUsers),
                'fake_users' => $fakeUsers,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/import-fake-users — import fake users.
     *
     * Migrated from public/api/admin/import-fake-users.php. Accepts either an
     * export file ({"fake_users": [...]}) or a bare array; update_existing opts
     * in to overwriting existing users. Success {success, imported, updated,
     * skipped, invalid, imported_nicknames, updated_nicknames, skipped_nicknames,
     * invalid_rows}; invalid input / empty set -> 400.
     */
    #[Route('/api/admin/import-fake-users', methods: 'POST', name: 'admin.fake-users.import', middleware: [AdminAuthMiddleware::class])]
    public function import(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            // Accept both an export file ({"fake_users": [...]}) and a bare array.
            $rows = $input['fake_users'] ?? $input;
            if (!is_array($rows) || $rows === []) {
                throw new InvalidArgumentException('No fake users to import');
            }

            // Opt-in: overwrite the settings of fake users that already exist.
            $updateExisting = !empty($input['update_existing']);

            $result = (new FakeUserService())->importFakeUsers(array_values($rows), $updateExisting);

            return Response::json([
                'success'            => true,
                'imported'           => count($result['imported']),
                'updated'            => count($result['updated']),
                'skipped'            => count($result['skipped']),
                'invalid'            => count($result['invalid']),
                'imported_nicknames' => $result['imported'],
                'updated_nicknames'  => $result['updated'],
                'skipped_nicknames'  => $result['skipped'],
                'invalid_rows'       => $result['invalid'],
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Admin: POST /api/admin/clear-fake-user-history — wipe a fake user's
     * conversations so its bot can be tested from scratch.
     *
     * Migrated from public/api/admin/clear-fake-user-history.php. Destructive,
     * so it keeps the legacy RBAC gate: only root/owner/administrator may call
     * it (otherwise 403). Input: id or nickname. Success {success, nickname,
     * cleared:{messages, threads, epochs}}; unknown id -> 404; invalid input ->
     * 400; failure -> 500 "Failed to clear the history".
     */
    #[Route('/api/admin/clear-fake-user-history', methods: 'POST', name: 'admin.fake-users.clear-history', middleware: [AdminAuthMiddleware::class])]
    public function clearHistory(): Response
    {
        // Destructive, so hold it to the same bar as impersonation.
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner', 'administrator'], true)) {
            return Response::json(['error' => 'Forbidden: not allowed to delete conversations'], 403);
        }

        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $nickname = trim((string) ($input['nickname'] ?? ''));

            if ($nickname === '') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new InvalidArgumentException('Fake user id or nickname is required');
                }

                $fakeUser = (new FakeUserService())->getFakeUserById($id);
                if ($fakeUser === null) {
                    return Response::json(['error' => 'Fake user not found'], 404);
                }

                $nickname = (string) $fakeUser['nickname'];
            }

            $cleared = (new BotService())->clearHistoryFor($nickname);

            \Pramnos\Logs\Logger::log(sprintf(
                'Admin %s cleared bot history for %s (%d messages, %d threads)',
                $currentUser['username'] ?? '?',
                $nickname,
                $cleared['messages'],
                $cleared['threads']
            ), 'radiochatbox');

            return Response::json([
                'success'  => true,
                'nickname' => $nickname,
                'cleared'  => $cleared,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('clear-fake-user-history error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to clear the history'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
