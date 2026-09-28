<?php

namespace App\Services\Refunds\Reply;

use App\Enums\Decision;

/**
 * Output-side safeguard. The model is told the decision, but we still verify
 * its draft does not contradict it, invent amounts, leak internals, or drift
 * off-format before any of it reaches a customer.
 */
class ReplyGuard
{
    private const APPROVAL_CLAIMS = '/\b(refund|request|it)\b[^.]{0,40}\b(has been|is|was|will be)\s+(approved|issued|processed|refunded)\b|\bwe(\'ll| will| have)\s+(refunded|refund|issue[d]?)\b/i';

    private const DENIAL_CLAIMS = '/\b(has been|is|was)\s+(denied|declined|rejected)\b|\bunable to (approve|refund|offer)\b|\bnot eligible\b/i';

    private const LEAKS = '/\b(policy engine|system prompt|prompt injection|risk (score|flag)|fraud|large language model|LLM|as an AI|classifier|SEC_|RISK_|WINDOW_|ITEM_|CLAIM_)\b/i';

    /** @return list<string> violations (empty when safe) */
    public function check(string $reply, ReplyContext $ctx): array
    {
        $violations = [];
        $length = mb_strlen($reply);

        if ($length < 40 || $length > 1500) {
            $violations[] = "length_out_of_bounds:{$length}";
        }

        if (preg_match(self::LEAKS, $reply, $m)) {
            $violations[] = "internal_leak:{$m[0]}";
        }

        if (! str_contains($reply, $ctx->reference)) {
            $violations[] = 'missing_reference';
        }

        // Every dollar amount mentioned must be the approved/proposed amount.
        preg_match_all('/\$\s?([\d,]+(?:\.\d{1,2})?)/', $reply, $amounts);
        foreach ($amounts[1] as $raw) {
            $value = (float) str_replace(',', '', $raw);
            if (abs($value - $ctx->amount) > 0.01 && ! $this->isPolicyNumber($value)) {
                $violations[] = "unexpected_amount:{$raw}";
            }
        }

        $claimsApproval = preg_match(self::APPROVAL_CLAIMS, $reply) === 1;
        $claimsDenial = preg_match(self::DENIAL_CLAIMS, $reply) === 1;

        if ($ctx->decision === Decision::Denied && $claimsApproval) {
            $violations[] = 'denied_but_claims_approval';
        }

        if ($ctx->decision === Decision::Escalated && ($claimsApproval || $claimsDenial)) {
            $violations[] = 'escalated_but_claims_final_outcome';
        }

        if ($ctx->decision === Decision::Approved && ! str_contains($reply, $ctx->formattedAmount())) {
            $violations[] = 'approved_missing_amount';
        }

        return $violations;
    }

    /** Numbers from the published policy (e.g. the $500 review threshold) are fine to quote. */
    private function isPolicyNumber(float $value): bool
    {
        return abs($value - (float) config('refund.human_review_threshold')) < 0.01;
    }
}
