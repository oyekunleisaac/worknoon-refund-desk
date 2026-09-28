<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupOrderRequest;
use App\Http\Requests\SubmitRefundRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\RefundResultResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\Refunds\RefundRequestProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundRequestController extends Controller
{
    /**
     * Verify the customer owns the order before opening a conversation.
     * Returns the same 404 for "no such order" and "not your order".
     */
    public function lookup(LookupOrderRequest $request): JsonResponse
    {
        $order = Customer::where('email', mb_strtolower($request->string('email')->trim()))
            ->first()
            ?->orders()
            ->with('items', 'customer')
            ->where('order_number', Order::normalizeNumber($request->input('order_number')))
            ->first();

        if (! $order) {
            return response()->json(['message' => "We couldn't find an order matching that email and order number."], 404);
        }

        return response()->json([
            'customer' => ['first_name' => $order->customer->firstName()],
            'order' => new OrderResource($order),
            'recent_requests' => RefundResultResource::collection(
                $order->refundRequests()->latest()->limit(5)->get()
            ),
        ]);
    }

    public function store(SubmitRefundRequest $request, RefundRequestProcessor $processor): JsonResponse
    {
        $refund = $processor->process(
            email: $request->input('email'),
            orderNumber: $request->input('order_number'),
            message: $request->input('message'),
            ip: $request->ip(),
        );

        return (new RefundResultResource($refund))->response()->setStatusCode(201);
    }

    /** Status check; requires the email as a lightweight proof of ownership. */
    public function show(Request $request, string $reference): RefundResultResource
    {
        $request->validate(['email' => ['required', 'email']]);

        $refund = RefundRequest::where('reference', strtoupper($reference))
            ->where('submitted_email', mb_strtolower(trim($request->query('email'))))
            ->firstOrFail();

        return new RefundResultResource($refund);
    }
}
