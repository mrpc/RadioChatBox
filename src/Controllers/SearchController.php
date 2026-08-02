<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;

/**
 * User-facing search of the public chat history. A short GET query returns the
 * most recent matching messages. Only public messages are searchable (private
 * conversations are never exposed here).
 */
final class SearchController
{
    /**
     * GET /api/search?q=term — recent public messages containing `q`
     * (case-insensitive, min 2 chars). 200 {success, query, results}; a too-short
     * query returns an empty result set rather than an error.
     */
    #[Route('/api/search', methods: 'GET', name: 'search.messages')]
    public function search(): Response
    {
        try {
            $q = trim((string) Request::getInstance()->get('q', '', 'get'));
            if (mb_strlen($q) < 2) {
                return Response::json(['success' => true, 'query' => $q, 'results' => []]);
            }

            $chatMode = (new ChatService())->getSetting('chat_mode') ?? 'both';
            if ($chatMode === 'private') {
                // No public chat to search.
                return Response::json(['success' => true, 'query' => $q, 'results' => []]);
            }

            $results = (new ChatService())->searchMessages($q, 50);
            return Response::json(['success' => true, 'query' => $q, 'results' => $results]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SearchController::search failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
