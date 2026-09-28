<?php

namespace App\Enums;

enum RequestStatus: string
{
    /** Pipeline is running. */
    case Processing = 'processing';

    /** Automatically approved or denied by the policy engine. */
    case Resolved = 'resolved';

    /** Escalated and waiting for a support agent. */
    case PendingReview = 'pending_review';

    /** A support agent made the final call on an escalated request. */
    case Reviewed = 'reviewed';

    /** The pipeline crashed; the request needs manual follow-up. */
    case Failed = 'failed';
}
