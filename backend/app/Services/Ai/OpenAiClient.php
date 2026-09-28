<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI Chat Completions implementation of LlmClient.
 *
 * Structured output uses a single function tool forced via tool_choice,
 * mirroring the Anthropic implementation, so the rest of the pipeline
 * (validation, policy, guard) is provider-agnostic.
 */
class OpenAiClient implements LlmClient
{
    private const RETRYABLE_STATUSES = [408, 409, 429, 500, 502, 503, 504];

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
            'max_completion_tokens' => $maxTokens,
            'messages' => $this->messages($system, $user),
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => $toolName,
                    'description' => $toolDescription,
                    'parameters' => $inputSchema,
                ],
            ]],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => $toolName]],
        ]);

        $call = collect($body['choices'][0]['message']['tool_calls'] ?? [])
            ->first(fn ($c) => ($c['function']['name'] ?? null) === $toolName);

        $arguments = json_decode($call['function']['arguments'] ?? '', true);

        if (! is_array($arguments)) {
            throw new LlmException('Model did not return the expected tool call.');
        }

        return $this->result($body, null, $arguments, $started);
    }

    public function text(string $system, string $user, int $maxTokens = 600): LlmResult
    {
        $started = hrtime(true);

        $body = $this->send([
            'model' => $this->model(),
            'max_completion_tokens' => $maxTokens,
            'messages' => $this->messages($system, $user),
        ]);

        $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));

        if ($text === '') {
            throw new LlmException('Model returned an empty response.');
        }

        return $this->result($body, $text, null, $started);
    }

    private function messages(string $system, string $user): array
    {
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    private function send(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new LlmException('OpenAI API key is not configured.');
        }

        try {
            /** @var Response $response */
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withToken($this->config['api_key'])
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
                ->post('/v1/chat/completions', $payload);
        } catch (RequestException $e) {
            $message = $e->response->json('error.message') ?? 'HTTP '.$e->response->status();

            throw new LlmException("OpenAI API error: {$message}", $e->response->status(), $e);
        } catch (Throwable $e) {
            throw new LlmException('OpenAI API unreachable: '.$e->getMessage(), 0, $e);
        }

        return $response->json() ?? [];
    }

    private function result(array $body, ?string $text, ?array $data, int $started): LlmResult
    {
        return new LlmResult(
            text: $text,
            data: $data,
            model: $body['model'] ?? $this->model(),
            inputTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            stopReason: $body['choices'][0]['finish_reason'] ?? null,
        );
    }
}
