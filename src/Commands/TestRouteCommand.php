<?php

namespace Hansonxyz\HeadlessBrowserTester\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Headless Browser Route Tester
 *
 * A comprehensive route debugging tool that uses Playwright to launch a real
 * browser and test Laravel routes, capturing diagnostic information about
 * the request, response, JavaScript execution, console output, and more.
 *
 * Features:
 * - User authentication bypass for testing protected routes
 * - Console capture (errors and logs)
 * - XHR/fetch request tracking
 * - DOM element verification and extraction
 * - localStorage/sessionStorage inspection
 * - Screenshot capture with device presets
 * - POST request support
 * - Redirect chain tracking
 */
class TestRouteCommand extends Command
{
    protected $signature = 'browser:test
        {url? : The URL to test (e.g., /dashboard, /api/users)}
        {--user= : Test as specific user ID or username (requires middleware)}
        {--install-middleware : Install HeadlessBrowserTesterAuth middleware}
        {--no-body : Suppress HTTP response body}
        {--follow-redirects : Follow HTTP redirects and show redirect chain}
        {--headers : Display all HTTP response headers}
        {--console : Display all browser console output}
        {--xhr-dump : Capture full XHR/fetch request details}
        {--xhr-list : Show simple list of XHR/fetch URLs and status codes}
        {--input-elements : List all form input elements}
        {--post= : Send POST request with JSON data}
        {--cookies : Display all browser cookies}
        {--wait-for= : Wait for CSS selector before capture}
        {--expect-element= : Verify element exists (fails if not found)}
        {--dump-element= : Extract HTML of element by CSS selector}
        {--dump-dimensions= : Add layout dimensions to matching elements}
        {--storage : Display localStorage and sessionStorage}
        {--eval= : Execute JavaScript and display result}
        {--timeout= : Navigation timeout in milliseconds (default: 30000)}
        {--screenshot-path= : Path to save screenshot}
        {--screenshot-width= : Screenshot width (px or preset: mobile, tablet, desktop)}
        {--full : Enable all display options}';

    protected $description = 'Test a route using a headless browser - captures response, console, XHR, DOM state, and more';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This command is not available in production.');
            return 1;
        }

        // Handle middleware installation
        if ($this->option('install-middleware')) {
            return $this->install_middleware();
        }

        $url = $this->argument('url');
        if (!$url) {
            $this->error('No URL provided.');
            $this->line('');
            $this->line('Usage: php artisan browser:test /path [options]');
            $this->line('');
            $this->line('Examples:');
            $this->line('  php artisan browser:test /dashboard');
            $this->line('  php artisan browser:test /admin --user=1');
            $this->line('  php artisan browser:test /api/data --xhr-list');
            $this->line('  php artisan browser:test /page --screenshot-path=/tmp/shot.png');
            $this->line('');
            $this->line('Post-load interactions (click buttons, test modals):');
            $this->line('  php artisan browser:test /page --user=1 --eval="$(\'button\').click(); await new Promise(r => setTimeout(r, 2000));"');
            $this->line('  php artisan browser:test /form --eval="$(\'#submit\').click(); await new Promise(r => setTimeout(r, 1000));"');
            $this->line('');
            $this->line('To install authentication middleware:');
            $this->line('  php artisan browser:test --install-middleware');
            return 1;
        }

        // Check middleware when --user is specified
        if ($this->option('user')) {
            if (!$this->middleware_is_registered()) {
                $this->error('HeadlessBrowserTesterAuth middleware is not registered.');
                $this->line('');
                $this->line('The --user option requires the HeadlessBrowserTesterAuth middleware.');
                $this->line('');
                $this->line('To install it automatically:');
                $this->line('  php artisan browser:test --install-middleware');
                $this->line('');
                $this->line('Or see the README.md for manual installation instructions.');
                return 1;
            }
        }

        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        // Verify Node.js is available
        $node_check = new Process(['node', '--version']);
        $node_check->run();

        if (!$node_check->isSuccessful()) {
            $this->error('Node.js is not installed or not in PATH');
            return 1;
        }

        // Check/install Playwright
        $package_path = dirname(__DIR__, 2);
        $playwright_check = new Process(['node', '-e', "require('playwright')"], $package_path);
        $playwright_check->run();

        if (!$playwright_check->isSuccessful()) {
            $this->warn('Playwright not installed. Installing...');
            $install = new Process(['npm', 'install', 'playwright'], $package_path);
            $install->setTimeout(300);
            $install->run(function ($type, $buffer) {
                echo $buffer;
            });

            if (!$install->isSuccessful()) {
                $this->error('Failed to install Playwright');
                return 1;
            }
            $this->info('Playwright installed');
        }

        // Check/install Chromium browser
        $browser_script = "const {chromium} = require('playwright'); " .
            "chromium.launch({headless:true}).then(b => {b.close(); process.exit(0);}).catch(() => process.exit(1));";
        $browser_check = new Process(['node', '-e', $browser_script], $package_path, null, null, 10);
        $browser_check->run();

        if (!$browser_check->isSuccessful()) {
            $this->info('Installing Chromium browser...');
            $browser_install = new Process(['npx', 'playwright', 'install', 'chromium'], $package_path);
            $browser_install->setTimeout(300);
            $browser_install->run();

            if (!$browser_install->isSuccessful()) {
                $this->error('Failed to install Chromium');
                $this->line('Run manually: npx playwright install chromium');
                return 1;
            }
            $this->info('Chromium installed');
        }

        // Build command arguments
        $script_path = $package_path . '/bin/browser-test.js';
        $args = ['node', $script_path, $url];

        $options = [
            'user' => 'user-id',
            'no-body' => 'no-body',
            'follow-redirects' => 'follow-redirects',
            'headers' => 'headers',
            'console' => 'console-log',
            'xhr-dump' => 'xhr-dump',
            'xhr-list' => 'xhr-list',
            'input-elements' => 'input-elements',
            'post' => 'post',
            'cookies' => 'cookies',
            'wait-for' => 'wait-for',
            'expect-element' => 'expect-element',
            'dump-element' => 'dump-element',
            'dump-dimensions' => 'dump-dimensions',
            'storage' => 'storage',
            'eval' => 'eval',
            'timeout' => 'timeout',
            'screenshot-path' => 'screenshot-path',
            'screenshot-width' => 'screenshot-width',
            'full' => 'full',
        ];

        foreach ($options as $opt => $arg_name) {
            $value = $this->option($opt);
            if ($value === true) {
                $args[] = "--{$arg_name}";
            } elseif ($value !== null && $value !== false) {
                $args[] = "--{$arg_name}={$value}";
            }
        }

        // Add auth key when user impersonation is requested
        if ($this->option('user')) {
            $auth_key = $this->generate_auth_key();
            $args[] = "--auth-key={$auth_key}";
        }

        // Validate timeout
        $timeout = $this->option('timeout') ?? 30000;
        $timeout = intval($timeout);
        if ($timeout < 5000) {
            $this->error('Timeout must be at least 5000ms');
            return 1;
        }

        // Environment variables for the script
        $base_url = config('headless-browser-tester.base_url') ?? config('app.url') ?? 'http://localhost';
        $session_cookie = config('headless-browser-tester.session_cookie', config('session.cookie', 'laravel_session'));

        $env = array_merge($_ENV, [
            'BASE_URL' => $base_url,
            'SESSION_COOKIE' => $session_cookie,
            'LARAVEL_LOG_PATH' => storage_path('logs/laravel.log'),
        ]);

        // Run the script
        $process_timeout = ($timeout / 1000) + 10;
        $process = new Process($args, $package_path, $env, null, $process_timeout);

        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        return $process->isSuccessful() ? 0 : 1;
    }

    /**
     * Generate the auth key from the Laravel app key.
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
     * Check if the HeadlessBrowserTesterAuth middleware is registered.
     */
    protected function middleware_is_registered(): bool
    {
        // Check if our middleware class exists in the app
        $app_middleware_path = app_path('Http/Middleware/HeadlessBrowserTesterAuth.php');

        if (file_exists($app_middleware_path)) {
            return true;
        }

        // Also check if using the package middleware directly
        $kernel_path = app_path('Http/Kernel.php');
        $bootstrap_path = base_path('bootstrap/app.php');

        // Laravel 11+ uses bootstrap/app.php
        if (file_exists($bootstrap_path)) {
            $bootstrap_content = file_get_contents($bootstrap_path);
            if (str_contains($bootstrap_content, 'HeadlessBrowserTesterAuth')) {
                return true;
            }
        }

        // Laravel 10 uses Http/Kernel.php
        if (file_exists($kernel_path)) {
            $kernel_content = file_get_contents($kernel_path);
            if (str_contains($kernel_content, 'HeadlessBrowserTesterAuth')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Install the HeadlessBrowserTesterAuth middleware.
     */
    protected function install_middleware(): int
    {
        // Check if already installed
        $app_middleware_path = app_path('Http/Middleware/HeadlessBrowserTesterAuth.php');

        if (file_exists($app_middleware_path)) {
            $this->info('HeadlessBrowserTesterAuth middleware already exists.');
            return 0;
        }

        // Create the middleware directory if it doesn't exist
        $middleware_dir = app_path('Http/Middleware');
        if (!is_dir($middleware_dir)) {
            mkdir($middleware_dir, 0755, true);
        }

        // Copy the middleware file
        $source_middleware = dirname(__DIR__) . '/Middleware/HeadlessBrowserTesterAuth.php';
        $middleware_content = file_get_contents($source_middleware);

        // Update namespace for app
        $middleware_content = str_replace(
            'namespace Hansonxyz\\HeadlessBrowserTester\\Middleware;',
            'namespace App\\Http\\Middleware;',
            $middleware_content
        );

        file_put_contents($app_middleware_path, $middleware_content);
        $this->info('Created: app/Http/Middleware/HeadlessBrowserTesterAuth.php');

        // Register the middleware
        $registered = $this->register_middleware_in_app();

        if ($registered) {
            $this->info('Middleware registered successfully.');
            $this->line('');
            $this->line('You can now use: php artisan browser:test /route --user=1');
        } else {
            $this->warn('Could not auto-register middleware. Please register manually.');
            $this->line('');
            $this->line('For Laravel 11+, add to bootstrap/app.php:');
            $this->line('  ->withMiddleware(function (Middleware $middleware) {');
            $this->line('      $middleware->append(\\App\\Http\\Middleware\\HeadlessBrowserTesterAuth::class);');
            $this->line('  })');
            $this->line('');
            $this->line('For Laravel 10, add to app/Http/Kernel.php $middleware array:');
            $this->line('  \\App\\Http\\Middleware\\HeadlessBrowserTesterAuth::class,');
        }

        return 0;
    }

    /**
     * Register middleware in the Laravel application.
     */
    protected function register_middleware_in_app(): bool
    {
        // Try Laravel 11+ style (bootstrap/app.php)
        $bootstrap_path = base_path('bootstrap/app.php');

        if (file_exists($bootstrap_path)) {
            $content = file_get_contents($bootstrap_path);

            // Check if already registered
            if (str_contains($content, 'HeadlessBrowserTesterAuth')) {
                return true;
            }

            // Look for withMiddleware section
            if (preg_match('/->withMiddleware\s*\(\s*function\s*\(\s*Middleware\s+\$middleware\s*\)\s*\{/', $content)) {
                // Add to existing withMiddleware
                $content = preg_replace(
                    '/(->withMiddleware\s*\(\s*function\s*\(\s*Middleware\s+\$middleware\s*\)\s*\{)/',
                    "$1\n        \$middleware->append(\\App\\Http\\Middleware\\HeadlessBrowserTesterAuth::class);",
                    $content
                );
                file_put_contents($bootstrap_path, $content);
                return true;
            }

            // Look for ->create() or ->withRouting to insert withMiddleware before
            if (preg_match('/(->withRouting\s*\()/', $content)) {
                $middleware_block = "->withMiddleware(function (Middleware \$middleware) {\n        \$middleware->append(\\App\\Http\\Middleware\\HeadlessBrowserTesterAuth::class);\n    })\n    ";
                $content = preg_replace(
                    '/(->withRouting\s*\()/',
                    $middleware_block . '$1',
                    $content
                );

                // Add use statement if not present
                if (!str_contains($content, 'use Illuminate\\Foundation\\Configuration\\Middleware')) {
                    $content = preg_replace(
                        '/(use Illuminate\\\\Foundation\\\\Application;)/',
                        "$1\nuse Illuminate\\Foundation\\Configuration\\Middleware;",
                        $content
                    );
                }

                file_put_contents($bootstrap_path, $content);
                return true;
            }
        }

        // Try Laravel 10 style (Http/Kernel.php)
        $kernel_path = app_path('Http/Kernel.php');

        if (file_exists($kernel_path)) {
            $content = file_get_contents($kernel_path);

            // Check if already registered
            if (str_contains($content, 'HeadlessBrowserTesterAuth')) {
                return true;
            }

            // Add to $middleware array
            if (preg_match('/protected\s+\$middleware\s*=\s*\[/', $content)) {
                $content = preg_replace(
                    '/(protected\s+\$middleware\s*=\s*\[)/',
                    "$1\n        \\App\\Http\\Middleware\\HeadlessBrowserTesterAuth::class,",
                    $content
                );
                file_put_contents($kernel_path, $content);
                return true;
            }
        }

        return false;
    }
}
