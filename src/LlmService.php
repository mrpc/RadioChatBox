<?php

namespace RadioChatBox;

/**
 * Thin client for the DeepSeek chat completions API.
 *
 * DeepSeek exposes an OpenAI-compatible endpoint, so the same code works with
 * any OpenAI-compatible provider by pointing bot_llm_base_url / bot_llm_model
 * somewhere else. Implemented with cURL to avoid pulling an HTTP client
 * dependency into the project (same approach as RadioStatusService).
 *
 * The API key and endpoint live in the settings table (admin panel), like the
 * other third-party keys in this project. Every bot_* setting is stripped from
 * the public settings payload, so the key is only readable through the
 * authenticated admin API. Matching env vars, when set, act as a fallback for
 * container deployments that prefer secrets outside the database.
 */
class LlmService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $maxTokens;

    /**
     * @param array<string,mixed> $overrides api_key, base_url, model,
     *                                      temperature, max_tokens, timeout
     */
    public function __construct(array $overrides = [])
    {
        $config = Config::get('llm', []);

        $this->apiKey = $this->pick($overrides, 'api_key', (string) ($config['api_key'] ?? ''));
        $this->baseUrl = rtrim(
            $this->pick($overrides, 'base_url', (string) ($config['base_url'] ?? 'https://api.deepseek.com')),
            '/'
        );
        $this->model = $this->pick($overrides, 'model', (string) ($config['model'] ?? 'deepseek-chat'));

        $this->timeout = isset($overrides['timeout']) && (int) $overrides['timeout'] > 0
            ? (int) $overrides['timeout']
            : (int) ($config['timeout'] ?? 20);

        // DeepSeek recommends ~1.3 for casual conversation.
        $this->temperature = isset($overrides['temperature']) && $overrides['temperature'] !== ''
            ? (float) $overrides['temperature']
            : 1.3;

        $this->maxTokens = isset($overrides['max_tokens']) && (int) $overrides['max_tokens'] > 0
            ? max(16, (int) $overrides['max_tokens'])
            : 300;
    }

    /**
     * Build a client from the admin settings (with env fallbacks).
     */
    public static function fromSettings(?SettingsService $settings = null): self
    {
        $settings ??= new SettingsService();

        return new self([
            'api_key' => $settings->get('bot_llm_api_key', ''),
            'base_url' => $settings->get('bot_llm_base_url', ''),
            'model' => $settings->get('bot_llm_model', ''),
            'temperature' => $settings->get('bot_llm_temperature', ''),
            'max_tokens' => $settings->get('bot_llm_max_tokens', ''),
        ]);
    }

    /**
     * Non-empty override wins, otherwise the fallback.
     *
     * @param array<string,mixed> $overrides
     */
    private function pick(array $overrides, string $key, string $fallback): string
    {
        $value = trim((string) ($overrides[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Whether an API key is configured. Callers should check this before
     * scheduling work that would need the API.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Send a chat completion request and return the assistant text.
     *
     * @param string                                  $systemPrompt
     * @param list<array{role:string,content:string}> $messages Conversation history, oldest first
     *
     * @return array{text:string,usage:array<string,mixed>,finish_reason:?string}
     *
     * @throws \RuntimeException on transport errors, HTTP errors or empty completions
     */
    public function chat(string $systemPrompt, array $messages): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'LLM API key is not configured (Admin > Settings > Fake User Auto-Replies)'
            );
        }

        if (empty($messages)) {
            throw new \InvalidArgumentException('At least one message is required');
        }

        $payload = [
            'model' => $this->model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
            'max_tokens' => $this->maxTokens,
            'stream' => false,
        ];

        // The reasoning model ignores sampling parameters, so only send them
        // for the standard chat models.
        if (!str_contains($this->model, 'reasoner')) {
            $payload['temperature'] = $this->temperature;
        }

        $response = $this->post('/chat/completions', $payload);

        $choice = $response['choices'][0] ?? null;
        $text = trim((string) ($choice['message']['content'] ?? ''));

        if ($text === '') {
            throw new \RuntimeException('LLM returned an empty completion');
        }

        return [
            'text' => $text,
            'usage' => $response['usage'] ?? [],
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode LLM request payload');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RadioChatBox/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new \RuntimeException('LLM request failed: ' . ($curlError ?: 'unknown cURL error'));
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? substr((string) $raw, 0, 300);
            throw new \RuntimeException("LLM request failed with HTTP {$status}: {$message}");
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM returned a non-JSON response');
        }

        return $decoded;
    }
}
