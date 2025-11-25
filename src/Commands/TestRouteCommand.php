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
        {--user= : Test as specific user ID (bypasses authentication)}
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
            return 1;
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
}
