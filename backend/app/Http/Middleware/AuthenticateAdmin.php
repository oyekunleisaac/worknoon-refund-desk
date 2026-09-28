<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal bearer-token guard for the support dashboard. A real deployment
 * would put agents behind SSO (e.g. Sanctum + OIDC) with per-agent
 * identities; a shared token keeps the assessment runnable in one command.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('refund.admin_token');

        if ($expected === '') {
            return response()->json(['message' => 'Admin API is disabled: set ADMIN_TOKEN.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid or missing admin token.'], 401);
        }

        return $next($request);
    }
}
