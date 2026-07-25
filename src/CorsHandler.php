<?php

namespace RadioChatBox;

class CorsHandler
{
    /**
     * The CORS headers an origin should receive, or an empty array when the
     * origin is not allowed.
     *
     * Split out of handle() so the decision itself can be tested: header() is a
     * no-op in CLI and cannot be observed without Xdebug.
     *
     * @param list<string> $allowedOrigins
     *
     * @return array<string,string>
     */
    public static function resolveHeaders(string $origin, array $allowedOrigins): array
    {
        $allowed = in_array('*', $allowedOrigins, true)
            || ($origin !== '' && in_array($origin, $allowedOrigins, true));

        if (!$allowed) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $origin !== '' ? $origin : '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Allow-Credentials' => 'true',
        ];
    }

    /**
     * Whether this is a CORS preflight request.
     *
     * Defaults to the current request method; REQUEST_METHOD is absent under
     * CLI, hence the null coalesce (reading it directly warned there).
     */
    public static function isPreflight(?string $method = null): bool
    {
        $method ??= (string) ($_SERVER['REQUEST_METHOD'] ?? '');

        return strtoupper($method) === 'OPTIONS';
    }

    public static function handle(): void
    {
        $headers = self::resolveHeaders(
            (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            (array) Config::get('allowed_origins', [])
        );

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // Handle preflight
        if (self::isPreflight()) {
            http_response_code(204);
            exit;
        }
    }
}
