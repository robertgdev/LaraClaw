<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to authenticate REST API requests using an API key.
 *
 * The API key should be provided in one of the following locations:
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
        $configuredKey = config('laraclaw.rest_api_key');

        // If no API key is configured, deny access (security by default)
        if (empty($configuredKey)) {
            return new JsonResponse([
                'error' => 'API key not configured',
                'message' => 'The REST API key has not been configured. Set LARACLAW_REST_API_KEY in your .env file.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Try to get the API key from various sources
        $providedKey = $this->extractApiKey($request);
        if (empty($providedKey)) {
            return new JsonResponse([
                'error' => 'API key required',
                'message' => 'Provide an API key via X-API-Key header, Authorization: Bearer, or api_key query parameter.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Use timing-safe comparison to prevent timing attacks
        if (! hash_equals($configuredKey, $providedKey)) {
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
