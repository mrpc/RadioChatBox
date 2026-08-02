<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ChatCommandService;

/**
 * Admin CRUD for custom chat slash-commands (/rules, /tip, …). The public side
 * (a user typing a command) is handled inline by SendController via
 * ChatCommandService; this controller only manages the definitions.
 */
class ChatCommandController
{
    /**
     * GET /api/admin/commands — list all commands. POST — create one. PUT —
     * update by id. DELETE — remove by id. All admin-only.
     *
     * Bodies: POST {command, response, description?, is_active?};
     * PUT {id, response, description?, is_active}; DELETE {id}. The framework
     * routes PUT/DELETE JSON into their own input stores (not $_POST).
     */
    #[Route('/api/admin/commands', methods: ['GET', 'POST', 'PUT', 'DELETE'], name: 'admin.commands', middleware: [AdminAuthMiddleware::class])]
    public function handle(): Response
    {
        // Read the method via the Request seam (populated from $_SERVER in
        // production) so PUT/DELETE are dispatched correctly and unit-testable.
        $method = strtoupper(Request::getInstance()->getRequestMethod());
        try {
            $service = new ChatCommandService();

            if ($method === 'GET') {
                return Response::json(['success' => true, 'commands' => $service->all()]);
            }

            if ($method === 'POST') {
                $input = $_POST;
                $id = $service->create(
                    (string) ($input['command'] ?? ''),
                    (string) ($input['response'] ?? ''),
                    isset($input['description']) ? (string) $input['description'] : null,
                    $this->boolField($input['is_active'] ?? true)
                );
                return Response::json(['success' => true, 'id' => $id]);
            }

            if ($method === 'PUT') {
                $request = Request::getInstance();
                $id = (int) $request->get('id', 0, 'put');
                if ($id <= 0) {
                    return Response::json(['error' => 'id is required'], 400);
                }
                $service->update(
                    $id,
                    (string) $request->get('response', '', 'put'),
                    ($d = $request->get('description', null, 'put')) !== null ? (string) $d : null,
                    $this->boolField($request->get('is_active', true, 'put'))
                );
                return Response::json(['success' => true]);
            }

            if ($method === 'DELETE') {
                $request = Request::getInstance();
                $id = (int) $request->get('id', 0, 'delete');
                if ($id <= 0) {
                    return Response::json(['error' => 'id is required'], 400);
                }
                $service->delete($id);
                return Response::json(['success' => true]);
            }

            return Response::json(['error' => 'Method not allowed'], 405);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ChatCommandController failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** Coerce a checkbox/string/bool into a real bool. */
    private function boolField(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
