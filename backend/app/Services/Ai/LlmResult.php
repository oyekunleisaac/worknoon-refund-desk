<?php

namespace App\Services\Ai;

final readonly class LlmResult
{
    /**
     * @param  array<string, mixed>|null  $data  Tool input for structured calls.
     */
    public function __construct(
        public ?string $text,
        public ?array $data,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
        public ?string $stopReason = null,
    ) {}

    public function usage(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'duration_ms' => $this->durationMs,
            'stop_reason' => $this->stopReason,
        ];
    }
}
