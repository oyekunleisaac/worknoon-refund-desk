<?php

namespace App\Http\Controllers;

use App\Services\Ai\LlmClient;
use App\Services\Refunds\PolicyDocument;
use App\Support\DemoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SupportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'service' => 'Worknoon Refund Desk API',
            'docs' => 'See README.md. All endpoints live under /api.',
        ]);
    }

    public function health(LlmClient $llm): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'ai' => [
                'mode' => $llm->isConfigured() ? 'llm' : 'fallback',
                'model' => $llm->isConfigured() ? $llm->model() : null,
            ],
            'demo_mode' => (bool) config('refund.demo_mode'),
        ], $database === 'ok' ? 200 : 503);
    }

    public function policy(PolicyDocument $document): JsonResponse
    {
        return response()->json([
            'markdown' => $document->render(),
            'parameters' => [
                'refund_window_days' => config('refund.refund_window_days'),
                'human_review_threshold' => config('refund.human_review_threshold'),
                'lost_in_transit_days' => config('refund.lost_in_transit_days'),
                'message_max_length' => config('refund.message_max_length'),
            ],
        ]);
    }

    public function scenarios(): JsonResponse
    {
        abort_unless(config('refund.demo_mode'), 404);

        return response()->json(DemoCatalog::scenarios());
    }
}
