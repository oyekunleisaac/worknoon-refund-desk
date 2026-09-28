<?php

namespace App\Http\Resources;

use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RefundRequest */
class AdminRefundRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'decision' => $this->decision?->value,
            'status' => $this->status->value,
            'reason_category' => $this->reason_category,
            'refund_amount' => (float) $this->refund_amount,
            'risk_flags' => $this->risk_flags ?? [],
            'ai_mode' => $this->ai_mode,
            'ai_model' => $this->ai_model,
            'submitted_email' => $this->submitted_email,
            'submitted_order_number' => $this->submitted_order_number,
            'message' => $this->message,
            'summary' => $this->classification['summary'] ?? null,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'customer_number' => $this->customer->customer_number,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'tier' => $this->customer->tier,
                'account_flagged' => $this->customer->account_flagged,
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? new OrderResource($this->order->loadMissing('items')) : null),
            'customer_reply' => $this->customer_reply,
            'resolution_message' => $this->resolution_message,
            'classification' => $this->classification,
            'policy_evaluation' => $this->policy_evaluation,
            'reviewed_by' => $this->reviewed_by,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'audit_events' => $this->whenLoaded('auditEvents', fn () => $this->auditEvents->map(fn ($e) => [
                'id' => $e->id,
                'stage' => $e->stage,
                'actor' => $e->actor,
                'summary' => $e->summary,
                'data' => $e->data,
                'duration_ms' => $e->duration_ms,
                'created_at' => $e->created_at->toIso8601String(),
            ])),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
