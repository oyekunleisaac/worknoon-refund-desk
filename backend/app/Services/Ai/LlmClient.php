<?php

namespace App\Services\Ai;

/**
 * Provider-agnostic contract for the two LLM capabilities the product needs.
 * Keeping this narrow lets us swap providers, fake it in tests, and reason
 * about exactly what the model is allowed to do (it cannot touch data).
 */
interface LlmClient
{
    public function isConfigured(): bool;

    public function model(): string;

    /**
     * Force the model to answer by calling a single tool whose input matches
     * the given JSON schema. Returns the tool input as an array.
     *
     * @throws LlmException
     */
    public function structured(string $system, string $user, string $toolName, string $toolDescription, array $inputSchema, int $maxTokens = 1024): LlmResult;

    /**
     * Plain text completion.
     *
     * @throws LlmException
     */
    public function text(string $system, string $user, int $maxTokens = 600): LlmResult;
}
