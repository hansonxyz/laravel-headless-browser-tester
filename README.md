# Laravel Headless Browser Tester

Debug Laravel routes using a headless browser. Captures HTTP response, JavaScript console output, XHR requests, DOM state, cookies, storage, and screenshots.

## Installation

```bash
composer require hansonxyz/laravel-headless-browser-tester:@dev
```

The package is currently in development and requires the `@dev` stability flag.

The package auto-discovers via Laravel's package discovery.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Node.js (for Playwright)

Playwright and Chromium are automatically installed on first run.

## Usage

```bash
# Basic route test
php artisan browser:test /dashboard

# Test as authenticated user (by ID)
php artisan browser:test /admin --user=1

# Test as authenticated user (by email/username)
php artisan browser:test /admin --user=admin@example.com

# Capture everything
php artisan browser:test /page --full

# Take a screenshot
php artisan browser:test /page --screenshot-path=/tmp/shot.png

# Mobile screenshot
php artisan browser:test /page --screenshot-path=/tmp/mobile.png --screenshot-width=mobile
```

## Options

| Option | Description |
|--------|-------------|
| `--user=<id\|username>` | Test as specific user ID (numeric) or username (string) |
| `--install-middleware` | Install the authentication middleware |
| `--no-body` | Suppress response body output |
| `--follow-redirects` | Show full redirect chain |
| `--headers` | Display response headers |
| `--console` | Display all console output |
| `--xhr-list` | List XHR/fetch requests |
| `--xhr-dump` | Full XHR request details |
| `--input-elements` | List form inputs |
| `--cookies` | Display cookies |
| `--storage` | Display localStorage/sessionStorage |
| `--expect-element=<sel>` | Fail if element not found |
| `--dump-element=<sel>` | Extract element HTML |
| `--dump-dimensions=<sel>` | Add layout dimensions to matching elements |
| `--wait-for=<sel>` | Wait for element before capture |
| `--eval=<code>` | Execute async JavaScript (supports await) |
| `--post=<json>` | Send POST request |
| `--timeout=<ms>` | Navigation timeout (default: 30000) |
| `--screenshot-path=<p>` | Save screenshot |
| `--screenshot-width=<w>` | Width: mobile (375), tablet (768), desktop (1920), or px |
| `--full` | Enable all display options |

## User Authentication

The `--user` option allows testing routes as an authenticated user. This requires the `HeadlessBrowserTesterAuth` middleware.

### Automatic Installation

```bash
php artisan browser:test --install-middleware
```

This will:
1. Create `app/Http/Middleware/HeadlessBrowserTesterAuth.php`
2. Register the middleware in your application (Laravel 10 Kernel.php or Laravel 11+ bootstrap/app.php)

### Manual Installation

If automatic installation doesn't work for your setup, create the middleware manually:

```php
// app/Http/Middleware/HeadlessBrowserTesterAuth.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
            abort(403, 'Headless Browser Tester: Invalid or missing X-Dev-Auth-Key header.');
        }

        // Determine if user_id is numeric (ID) or string (username)
        $user = $this->resolve_user($user_id);

        if (!$user) {
            abort(404, "Headless Browser Tester: User '{$user_id}' not found.");
        }

        auth()->login($user);

        return $next($request);
    }

    protected function generate_auth_key(): string
    {
        $app_key = config('app.key');

        if (str_starts_with($app_key, 'base64:')) {
            $app_key = base64_decode(substr($app_key, 7));
        }

        return hash('sha256', $app_key . ':headless-browser-tester');
    }

    protected function resolve_user(string $user_id): ?object
    {
        $model_class = config('headless-browser-tester.user_model', 'App\\Models\\User');

        if (ctype_digit($user_id)) {
            return $model_class::find((int) $user_id);
        }

        $username_field = config('headless-browser-tester.username_field', 'email');
        return $model_class::where($username_field, $user_id)->first();
    }
}
```

Then register in your application:

**Laravel 11+ (bootstrap/app.php):**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\HeadlessBrowserTesterAuth::class);
})
```

**Laravel 10 (app/Http/Kernel.php):**
```php
protected $middleware = [
    \App\Http\Middleware\HeadlessBrowserTesterAuth::class,
    // ... other middleware
];
```

### Security

The authentication system uses a secure key derived from your Laravel `APP_KEY`:
- The command generates an `X-Dev-Auth-Key` header using `hash('sha256', $app_key . ':headless-browser-tester')`
- The middleware validates this key before allowing user impersonation
- If `X-Dev-Auth-User-Id` is present but the key is missing or invalid, the request fails with 403
- User impersonation is completely disabled in production environments

### User Lookup

The `--user` option accepts:
- **Numeric value**: Looks up user by primary key ID (e.g., `--user=1`)
- **String value**: Looks up user by the configured username field (e.g., `--user=admin@example.com`)

Configure the username field in your config:
```php
'username_field' => 'email',  // or 'username', 'name', etc.
```

## Layout Dimensions

The `--dump-dimensions` option injects `data-dimensions` attributes into matching elements, useful for AI agents or automated tools diagnosing layout issues without visual inspection.

```bash
# Add dimensions to all cards, then extract container HTML
php artisan browser:test /page --dump-dimensions=".card" --dump-element=".container"
```

Each matching element receives a `data-dimensions` attribute with JSON:

```html
<div class="card" data-dimensions='{"x":20,"y":100,"w":300,"h":200,"margin":0,"padding":"20 15 20 15"}'>
```

Fields:
- `x`, `y` - Absolute position on page
- `w`, `h` - Element width and height
- `margin` - Single value if uniform, or "top right bottom left"
- `padding` - Single value if uniform, or "top right bottom left"

## JavaScript Evaluation

The `--eval` option executes JavaScript after the page fully loads and supports `await` syntax for async operations. This enables testing post-load interactions like button clicks, modal openings, and form submissions.

### Time Delay Pattern

Use `await new Promise(r => setTimeout(r, ms))` to wait for async operations:

```bash
# Click a button and wait 2 seconds for modal to appear
php artisan browser:test /contacts --user=1 \
    --eval="$('button.add').click(); await new Promise(r => setTimeout(r, 2000));"

# Submit a form and wait for response
php artisan browser:test /settings --user=1 \
    --eval="$('#save-btn').click(); await new Promise(r => setTimeout(r, 1000));"
```

### Multiple Sequential Actions

```bash
# Fill form fields and submit
php artisan browser:test /register \
    --eval="$('#name').val('Test User'); $('#email').val('test@example.com'); $('form').submit(); await new Promise(r => setTimeout(r, 1000));"
```

### Return Values

The eval result is displayed after execution:

```bash
# Get current page state
php artisan browser:test /dashboard --user=1 \
    --eval="return { url: location.href, title: document.title };"
```

### Combining with Other Options

Eval runs before screenshots and element dumps, so you can capture the result of interactions:

```bash
# Click button, wait for modal, then screenshot
php artisan browser:test /page --user=1 \
    --eval="$('.open-modal').click(); await new Promise(r => setTimeout(r, 1000));" \
    --screenshot-path=/tmp/modal.png

# Click button, wait, then dump the modal HTML
php artisan browser:test /page --user=1 \
    --eval="$('.open-modal').click(); await new Promise(r => setTimeout(r, 1000));" \
    --dump-element=".modal"
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=config --provider="Hansonxyz\HeadlessBrowserTester\HeadlessBrowserTesterServiceProvider"
```

Options:
- `base_url` - Override APP_URL for testing
- `timeout` - Default navigation timeout
- `screenshot.width/height` - Default dimensions
- `devices` - Device preset definitions
- `user_model` - Model class for user lookup
- `username_field` - Field for username lookups (default: 'email')
- `session_cookie` - Laravel session cookie name

## Security

- This package is disabled in production environments
- The `--user` option requires valid authentication key derived from APP_KEY
- User impersonation only works when middleware is properly installed
- All security checks are enforced at the middleware level

## License

MIT
