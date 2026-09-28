<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin client for the Anthropic Messages API.
 *
 * Deliberately uses Laravel's HTTP client rather than an SDK: the surface we
 * need is tiny (one endpoint), and this keeps retries, timeouts and test
 * fakes (Http::fake) in idiomatic Laravel.
 */
class AnthropicClient implements LlmClient
{
    private const RETRYABLE_STATUSES = [408, 409, 429, 500, 502, 503, 504, 529];

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));

        return $key !== '' && ! str_starts_with($key, 'your-');
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function structured(string $system, string $user, string $toolName, string $toolDescription, array $inputSchema, int $maxTokens = 1024): LlmResult
    {
        $started = hrtime(true);

        $body = $this->send([
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'tools' => [[
                'name' => $toolName,
                'description' => $toolDescription,
                'input_schema' => $inputSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ]);

        $toolUse = collect($body['content'] ?? [])
            ->first(fn ($block) => ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName);

        if (! is_array($toolUse['input'] ?? null)) {
            throw new LlmException('Model did not return the expected tool call.');
        }

        return $this->result($body, null, $toolUse['input'], $started);
    }

    public function text(string $system, string $user, int $maxTokens = 600): LlmResult
    {
        $started = hrtime(true);

        $body = $this->send([
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);

        $text = collect($body['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty response.');
        }

        return $this->result($body, trim($text), null, $started);
    }

    private function send(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new LlmException('Anthropic API key is not configured.');
        }

        try {
            /** @var Response $response */
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withHeaders([
                    'x-api-key' => $this->config['api_key'],
                    'anthropic-version' => $this->config['version'],
                ])
                ->acceptJson()
                ->asJson()
                ->timeout($this->config['timeout'])
                ->connectTimeout(5)
                ->throw()
                ->retry(
                    times: max(1, 1 + (int) $this->config['max_retries']),
                    sleepMilliseconds: fn (int $attempt) => 400 * (2 ** ($attempt - 1)),
                    when: fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE_STATUSES, true)),
                )
                ->post('/v1/messages', $payload);
        } catch (RequestException $e) {
            $message = $e->response->json('error.message') ?? 'HTTP '.$e->response->status();

            throw new LlmException("Anthropic API error: {$message}", $e->response->status(), $e);
        } catch (Throwable $e) {
            throw new LlmException('Anthropic API unreachable: '.$e->getMessage(), 0, $e);
        }

        return $response->json() ?? [];
    }

    private function result(array $body, ?string $text, ?array $data, int $started): LlmResult
    {
        return new LlmResult(
            text: $text,
            data: $data,
            model: $body['model'] ?? $this->model(),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            stopReason: $body['stop_reason'] ?? null,
        );
    }
}
