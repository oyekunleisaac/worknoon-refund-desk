<?php

namespace App\Services\Refunds\Reply;

use App\Enums\Decision;

/**
 * Deterministic replies used when the LLM is unavailable or its draft fails
 * the output guard. Always correct, if less warm.
 */
class TemplateReplyComposer
{
    public function compose(ReplyContext $ctx): string
    {
        $reasons = $ctx->reasons ? ' '.implode(' ', $ctx->reasons) : '';
        $sorry = $ctx->sentiment !== 'calm' ? "I'm sorry for the trouble with your order. " : '';

        $body = match ($ctx->decision) {
            Decision::Approved => "{$sorry}Good news: your refund of {$ctx->formattedAmount()} for "
                .$this->list($ctx->refundedItems)." from order {$ctx->orderNumber} has been approved."
                .$reasons.' The money will be returned to your original payment method within 5-10 business days.',
            Decision::Denied => "{$sorry}Thank you for reaching out about order {$ctx->orderNumber}. Unfortunately we're unable to approve a refund for this request."
                .$reasons.' If you think something is missing, just reply with more details and our team will take another look.',
            Decision::Escalated => "{$sorry}Thank you for reaching out about order {$ctx->orderNumber}. Your request has been passed to a support specialist for review."
                .$reasons.' You can expect an update within 1-2 business days.',
        };

        return "Hi {$ctx->firstName},\n\n{$body}\n\nReference: {$ctx->reference}\n\nWorknoon Support";
    }

    private function list(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? 'your order';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
