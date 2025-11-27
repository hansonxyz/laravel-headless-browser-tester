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

# Test as authenticated user
php artisan browser:test /admin --user=1

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
| `--user=<id>` | Test as specific user ID (requires middleware) |
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
| `--wait-for=<sel>` | Wait for element before capture |
| `--eval=<code>` | Execute JavaScript |
| `--post=<json>` | Send POST request |
| `--timeout=<ms>` | Navigation timeout (default: 30000) |
| `--screenshot-path=<p>` | Save screenshot |
| `--screenshot-width=<w>` | Width: mobile (375), tablet (768), desktop (1920), or px |
| `--full` | Enable all display options |

## User Authentication

To test authenticated routes, implement middleware that checks for the `X-Dev-Auth-User-Id` header:

```php
// app/Http/Middleware/DevAuthMiddleware.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->environment('production')) {
            $userId = $request->header('X-Dev-Auth-User-Id');
            if ($userId) {
                auth()->loginUsingId($userId);
            }
        }
        return $next($request);
    }
}
```

Register in your HTTP kernel for routes you want to test.

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
- `session_cookie` - Laravel session cookie name

## Security

This package is disabled in production environments. The `--user` option should only be used in development/testing.

## License

MIT
