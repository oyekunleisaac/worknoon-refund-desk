<?php

namespace App\Http\Resources;

use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing view of a refund request. Deliberately excludes risk
 * flags, rule traces and AI internals.
 *
 * @mixin RefundRequest
 */
class RefundResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'order_number' => $this->submitted_order_number,
            'decision' => $this->decision?->value,
            'status' => $this->status->value,
            'refund_amount' => $this->decision?->value === 'approved' ? (float) $this->refund_amount : null,
            'reply' => $this->customer_reply,
            'resolution' => $this->resolution_message,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
