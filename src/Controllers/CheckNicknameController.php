<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Http\Validate;

/**
 * POST /api/check-nickname — whether a nickname is free for this session.
 *
 * Migrated from public/api/check-nickname.php. The framework Request has already
 * decoded the JSON body into $_POST. Behaviour preserved: empty body / missing
 * nickname -> 400, otherwise {success, available}.
 */
final class CheckNicknameController
{
    #[Route('/api/check-nickname', methods: 'POST', name: 'check-nickname.store')]
    public function store(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $error = Validate::check($input, ['nickname' => 'required'], [
                'nickname.required' => 'Nickname is required',
            ]);
            if ($error) {
                return $error;
            }

            $nickname  = (string) $input['nickname'];
            $sessionId = $input['sessionId'] ?? '';

            $available = (new ChatService())->isNicknameAvailable($nickname, $sessionId);

            return Response::json(['success' => true, 'available' => $available]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
