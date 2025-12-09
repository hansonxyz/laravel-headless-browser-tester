<?php

namespace Hansonxyz\HeadlessBrowserTester\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for headless browser tester user impersonation.
 *
 * This middleware allows the browser:test command to authenticate as a specific
 * user when testing protected routes. It validates the request using a hashed
 * version of the Laravel app key for security.
 *
 * SECURITY: This middleware is disabled in production environments.
 */
class HeadlessBrowserTesterAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never allow in production
        if (app()->environment('production')) {
            return $next($request);
        }

        $user_id = $request->header('X-Dev-Auth-User-Id');

        // No user impersonation requested
        if (!$user_id) {
            return $next($request);
        }

        // User impersonation requested - auth key is REQUIRED
        $provided_key = $request->header('X-Dev-Auth-Key');
        $expected_key = $this->generate_auth_key();

        if (!$provided_key || !hash_equals($expected_key, $provided_key)) {
            abort(403, 'Headless Browser Tester: Invalid or missing X-Dev-Auth-Key header. This is a security measure to prevent unauthorized user impersonation.');
        }

        // Determine if user_id is numeric (ID) or string (username)
        $user = $this->resolve_user($user_id);

        if (!$user) {
            abort(404, "Headless Browser Tester: User '{$user_id}' not found.");
        }

        auth()->login($user);

        return $next($request);
    }

    /**
     * Generate the expected auth key from the Laravel app key.
     */
    protected function generate_auth_key(): string
    {
        $app_key = config('app.key');

        // Remove base64: prefix if present
        if (str_starts_with($app_key, 'base64:')) {
            $app_key = base64_decode(substr($app_key, 7));
        }

        return hash('sha256', $app_key . ':headless-browser-tester');
    }

    /**
     * Resolve user by ID (numeric) or username field (string).
     */
    protected function resolve_user(string $user_id): ?object
    {
        $model_class = config('headless-browser-tester.user_model', 'App\\Models\\User');

        if (!class_exists($model_class)) {
            abort(500, "Headless Browser Tester: User model class '{$model_class}' not found.");
        }

        // Numeric = user ID, otherwise username
        if (ctype_digit($user_id)) {
            return $model_class::find((int) $user_id);
        }

        // String = username lookup
        $username_field = config('headless-browser-tester.username_field', 'email');

        return $model_class::where($username_field, $user_id)->first();
    }
}
