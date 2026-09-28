<?php

namespace App\Services\Refunds;

/**
 * Renders the human-readable refund policy with live values from
 * config/refund.php, so the document and the engine never disagree.
 */
class PolicyDocument
{
    public function render(): string
    {
        $template = file_get_contents(resource_path('policy/refund_policy.md'));

        return strtr($template, [
            '{{window_days}}' => (string) config('refund.refund_window_days'),
            '{{review_threshold}}' => number_format((float) config('refund.human_review_threshold'), 0),
            '{{lost_days}}' => (string) config('refund.lost_in_transit_days'),
            '{{risk_lookback_days}}' => (string) config('refund.risk.lookback_days'),
            '{{risk_max_refunds}}' => (string) config('refund.risk.max_refunds_in_lookback'),
        ]);
    }
}
