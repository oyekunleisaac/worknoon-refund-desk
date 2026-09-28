<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class DemoController extends Controller
{
    /** Restore the mock CRM to its original state so scenarios can be replayed. */
    public function reset(): JsonResponse
    {
        abort_unless(config('refund.demo_mode'), 404);

        Artisan::call('demo:reset', ['--force' => true]);

        return response()->json(['message' => 'Demo data restored.']);
    }
}
