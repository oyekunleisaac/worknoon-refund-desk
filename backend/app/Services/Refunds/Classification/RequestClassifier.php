<?php

namespace App\Services\Refunds\Classification;

use App\Models\Order;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmException;

/**
 * Chooses the classification strategy and degrades gracefully: LLM first,
 * keyword rules if the LLM is unconfigured or failing.
 */
class RequestClassifier
{
    public ?string $lastError = null;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly LlmRequestClassifier $llmClassifier,
        private readonly KeywordRequestClassifier $fallback,
    ) {}

    public function classify(string $message, Order $order): Classification
    {
        $this->lastError = null;

        if (! $this->llm->isConfigured()) {
            $this->lastError = 'No ANTHROPIC_API_KEY configured; using keyword fallback.';

            return $this->fallback->classify($message, $order);
        }

        try {
            return $this->llmClassifier->classify($message, $order);
        } catch (LlmException $e) {
            report($e);
            $this->lastError = $e->getMessage();

            return $this->fallback->classify($message, $order);
        }
    }
}
