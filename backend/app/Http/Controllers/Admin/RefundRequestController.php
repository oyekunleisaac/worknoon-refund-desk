<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRefundRequest;
use App\Http\Resources\AdminRefundRequestResource;
use App\Models\RefundRequest;
use App\Services\Refunds\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RefundRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'decision' => ['nullable', 'in:approved,denied,escalated'],
            'status' => ['nullable', 'in:processing,resolved,pending_review,reviewed,failed'],
            'flagged' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $requests = RefundRequest::query()
            ->with('customer')
            ->when($validated['decision'] ?? null, fn ($q, $v) => $q->where('decision', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('flagged'), fn ($q) => $q->whereNotNull('risk_flags'))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw('lower(reference) like ?', [$like])
                    ->orWhereRaw('lower(submitted_email) like ?', [$like])
                    ->orWhereRaw('lower(submitted_order_number) like ?', [$like]));
            })
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20);

        return AdminRefundRequestResource::collection($requests);
    }

    public function show(RefundRequest $refundRequest): AdminRefundRequestResource
    {
        return new AdminRefundRequestResource($refundRequest->load('customer', 'order.items', 'auditEvents'));
    }

    public function review(ReviewRefundRequest $request, RefundRequest $refundRequest, ReviewService $reviews): AdminRefundRequestResource
    {
        $reviewed = $reviews->review(
            $refundRequest,
            $request->input('action'),
            $request->input('note'),
            $request->input('reviewer'),
        );

        return new AdminRefundRequestResource($reviewed->load('customer', 'order.items', 'auditEvents'));
    }

    public function stats(): JsonResponse
    {
        $base = RefundRequest::query()->whereNotNull('decision');

        $byDecision = (clone $base)->selectRaw('decision, count(*) as total')->groupBy('decision')->pluck('total', 'decision');
        $byReason = (clone $base)->whereNotNull('reason_category')
            ->selectRaw('reason_category, count(*) as total')->groupBy('reason_category')->pluck('total', 'reason_category');
        $total = (int) $byDecision->sum();

        return response()->json([
            'total' => $total,
            'approved' => (int) ($byDecision['approved'] ?? 0),
            'denied' => (int) ($byDecision['denied'] ?? 0),
            'escalated' => (int) ($byDecision['escalated'] ?? 0),
            'pending_review' => RefundRequest::whereIn('status', ['pending_review', 'failed'])->count(),
            'automation_rate' => $total ? round(RefundRequest::where('status', 'resolved')->count() / $total, 3) : null,
            'refunded_amount' => (float) RefundRequest::where('decision', 'approved')->sum('refund_amount'),
            'flagged' => RefundRequest::whereNotNull('risk_flags')->count(),
            'by_reason' => $byReason,
        ]);
    }
}
