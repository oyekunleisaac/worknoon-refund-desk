<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'carrier_status' => $this->carrier_status,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'category' => $item->category,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'final_sale' => $item->final_sale,
                'refunded' => $item->isRefunded(),
            ])),
        ];
    }
}
