<?php

namespace App\Services\Refunds;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Human-in-the-loop resolution of escalated requests.
 */
class ReviewService
{
    public function __construct(private readonly RefundExecutor $executor) {}

    public function review(RefundRequest $request, string $action, string $note, string $reviewer): RefundRequest
    {
        return DB::transaction(function () use ($request, $action, $note, $reviewer) {
            $request = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, [RequestStatus::PendingReview, RequestStatus::Failed], true)) {
                throw ValidationException::withMessages(['action' => 'This request is not awaiting review.']);
            }

            $order = $request->order;
            $customerName = $request->customer?->firstName() ?? 'there';

            if ($action === 'approve') {
                $itemIds = $request->item_ids ?? [];

                if (! $order || $itemIds === []) {
                    throw ValidationException::withMessages(['action' => 'There are no refundable items proposed for this request.']);
                }

                $amount = $this->executor->execute($order, $itemIds);
                $message = "Hi {$customerName},\n\nA member of our support team has reviewed your request and approved a refund of $"
                    .number_format($amount, 2).'. It will be returned to your original payment method within 5-10 business days.';
                $decision = Decision::Approved;
            } else {
                $amount = 0;
                $message = "Hi {$customerName},\n\nA member of our support team has carefully reviewed your request. Unfortunately we're unable to approve a refund in this case.";
                $decision = Decision::Denied;
            }

            $message .= "\n\nReference: {$request->reference}\n\nWorknoon Support";

            $request->update([
                'decision' => $decision,
                'status' => RequestStatus::Reviewed,
                'refund_amount' => $amount,
                'reviewed_by' => $reviewer,
                'review_note' => $note,
                'reviewed_at' => now(),
                'resolution_message' => $message,
            ]);

            $request->audit('review', 'human', "{$reviewer} ".($decision === Decision::Approved ? 'approved' : 'denied').' the request after review.', [
                'note' => $note,
                'amount' => $amount,
            ]);

            return $request->fresh();
        });
    }
}
