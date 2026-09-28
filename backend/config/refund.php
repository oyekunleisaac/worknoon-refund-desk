<?php

/*
|--------------------------------------------------------------------------
| Refund policy parameters
|--------------------------------------------------------------------------
|
| These values are the single source of truth for the deterministic policy
| engine (App\Domain\Refunds\Policy\RefundPolicyEngine). The human-readable
| policy (resources/policy/refund_policy.md) is rendered with these values
| interpolated, so the document customers read can never drift from the
| rules the engine enforces.
|
*/

return [

    // Days after delivery during which a refund may be requested.
    'refund_window_days' => (int) env('REFUND_WINDOW_DAYS', 30),

    // Refunds strictly above this amount always require human review.
    'human_review_threshold' => (float) env('REFUND_HUMAN_REVIEW_THRESHOLD', 500),

    // A package still "in transit" is only considered lost after this many days.
    'lost_in_transit_days' => (int) env('REFUND_LOST_IN_TRANSIT_DAYS', 10),

    // Fraud signal: refunds received by the same customer within the lookback.
    'risk' => [
        'lookback_days' => 180,
        'max_refunds_in_lookback' => 3,
    ],

    // Below this classifier confidence the request goes to a human.
    'min_ai_confidence' => 0.6,

    // Tolerance before a customer's stated amount is considered conflicting.
    'claimed_amount_tolerance' => 0.10,

    // Hard input limits for customer messages.
    'message_max_length' => 2000,

    'currency' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Operational settings
    |--------------------------------------------------------------------------
    */

    // Shared bearer token protecting the support dashboard API.
    'admin_token' => env('ADMIN_TOKEN'),

    // Exposes demo scenarios and the "reset demo data" endpoint.
    'demo_mode' => (bool) env('DEMO_MODE', true),

];
