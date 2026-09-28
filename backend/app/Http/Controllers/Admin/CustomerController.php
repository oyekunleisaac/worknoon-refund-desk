<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = Customer::with(['orders' => fn ($q) => $q->latest('placed_at')->with('items')])
            ->withCount('refundRequests')
            ->orderBy('customer_number')
            ->get();

        return response()->json($customers->map(fn (Customer $c) => [
            'id' => $c->id,
            'customer_number' => $c->customer_number,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'tier' => $c->tier,
            'account_flagged' => $c->account_flagged,
            'flag_reason' => $c->flag_reason,
            'notes' => $c->notes,
            'customer_since' => $c->customer_since?->toDateString(),
            'refund_requests_count' => $c->refund_requests_count,
            'orders' => OrderResource::collection($c->orders),
        ]));
    }
}
