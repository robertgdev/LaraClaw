<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function Safe\preg_match;

/**
 * Middleware to authenticate REST API requests using an API key.
 *
 * The provided key is validated against two configured keys (in order):
 *   1. LARACLAW_REST_API_KEY  — dedicated REST API key (optional)
 *   2. LARACLAW_SERVER_API_KEY — the WebSocket / server key (always set)
 *
 * Access is granted if the provided key matches EITHER configured key.
 * This allows pure REST clients to use a dedicated key while the Vue frontend
 * (which only knows the server/WS key entered at login) also gets access.
 *
 * The API key must be provided in one of the following locations:
 * - X-API-Key header
 * - Authorization header with Bearer scheme
 * - api_key query parameter
 */
class RestApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $restApiKey    = config('laraclaw.rest_api_key');
        $serverApiKey  = config('laraclaw.server_api_key');

        // At least one key must be configured
        if (empty($restApiKey) && empty($serverApiKey)) {
            return new JsonResponse([
                'error' => 'API key not configured',
                'message' => 'No API key has been configured. Set LARACLAW_REST_API_KEY or LARACLAW_SERVER_API_KEY in your .env file.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $providedKey = $this->extractApiKey($request);

        if (empty($providedKey)) {
            return new JsonResponse([
                'error' => 'API key required',
                'message' => 'Provide an API key via X-API-Key header, Authorization: Bearer, or api_key query parameter.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Accept the request if the provided key matches EITHER configured key.
        // Use timing-safe comparison to prevent timing attacks.
        $matchesRestKey   = !empty($restApiKey)   && hash_equals($restApiKey,   $providedKey);
        $matchesServerKey = !empty($serverApiKey) && hash_equals($serverApiKey, $providedKey);

        if (!$matchesRestKey && !$matchesServerKey) {
            return new JsonResponse([
                'error' => 'Invalid API key',
                'message' => 'The provided API key is not valid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Extract the API key from the request.
     *
     * Checks in order:
     * 1. X-API-Key header
     * 2. Authorization header (Bearer token)
     * 3. api_key query parameter
     */
    protected function extractApiKey(Request $request): ?string
    {
        // Check X-API-Key header
        if ($request->hasHeader('X-API-Key')) {
            return $request->header('X-API-Key');
        }

        // Check Authorization header with Bearer scheme
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check api_key query parameter
        if ($request->has('api_key')) {
            return $request->query('api_key');
        }

        return null;
    }
}
