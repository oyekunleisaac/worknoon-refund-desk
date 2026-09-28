<?php

namespace App\Services\Refunds\Security;

/**
 * First, cheap line of defence against prompt injection and social
 * engineering. It is intentionally conservative: a match never grants or
 * denies anything by itself, it only routes the request to a human.
 *
 * This is one layer of several (see README "Security"): the model is also
 * instructed to flag manipulation, never sees tools that can act, and its
 * output is validated before a deterministic engine makes the decision.
 */
class PromptInjectionDetector
{
    /** @var array<string, string> label => regex */
    private const PATTERNS = [
        'instruction_override' => '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|any|your|the)\b[^.\n]{0,20}\b(instructions?|rules?|prompts?|polic(y|ies)|guidelines?)/i',
        'role_reassignment' => '/\b(you are now|you\'re now|from now on you|act as (an?|the)|pretend (to be|you are)|roleplay as)\b/i',
        'prompt_exfiltration' => '/\b(reveal|show|print|repeat|output|display)\b[^.\n]{0,30}\b(system prompt|instructions|your prompt|hidden rules|configuration)\b/i',
        'jailbreak_keywords' => '/\b(system prompt|developer mode|jailbreak|DAN mode|do anything now|prompt injection)\b/i',
        'policy_bypass' => '/\b(bypass|skip|circumvent|disable|turn off)\b[^.\n]{0,30}\b(polic(y|ies)|rules?|review|verification|checks?|safeguards?|limits?)\b/i',
        'forced_outcome' => '/\b(you must|you have to|you are required to|you are instructed to|you will now)\b[^.\n]{0,25}\b(approve|authori[sz]e|issue|grant)\b/i',
        'forced_approval' => '/\b(approve|authori[sz]e|grant)\b[^.\n]{0,20}\b(refund|request)\b[^.\n]{0,15}\b(immediately|automatically|without (any )?(review|checks?|questions?|verification))\b/i',
        'authority_claim' => '/\b(i am|i\'m|this is)\s+(an?\s+|the\s+|your\s+)?(worknoon\s+)?(admin(istrator)?|manager|supervisor|developer|engineer|ceo|staff member|employee|worknoon (staff|team|support))\b/i',
        'override_code' => '/\b(admin|manager|supervisor|override|authori[sz]ation|approval)\s+(code|override|token|password|key)\b/i',
        'fake_markup' => '/(<\/?\s*(system|assistant|instructions?|admin|tool)\b|\[\/?INST\]|###\s*(system|instruction)|<\|im_start\|>)/i',
        'structured_payload' => '/["\']?(decision|status|refund_amount|approved)["\']?\s*[:=]\s*["\']?(approved|true|escalated|denied|\d{3,})/i',
    ];

    public function scan(string $text): InjectionScan
    {
        $signals = [];

        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $signals[] = $label;
            }
        }

        return new InjectionScan(suspicious: $signals !== [], signals: $signals);
    }
}
