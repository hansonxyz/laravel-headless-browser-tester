#!/usr/bin/env node

/**
 * Laravel Headless Browser Tester
 * Tests Laravel routes using Playwright headless browser
 *
 * Usage: node browser-test.js <route> [options]
 */

const { chromium } = require('playwright');
const fs = require('fs');

const DEVICE_PRESETS = {
    'mobile': 375,
    'tablet': 768,
    'desktop': 1920
};

function parseArgs() {
    const args = process.argv.slice(2);

    if (args.length === 0 || args.includes('--help')) {
        console.log('Laravel Headless Browser Tester');
        console.log('');
        console.log('Usage: node browser-test.js <route> [options]');
        console.log('');
        console.log('Options:');
        console.log('  --user-id=<id>         Test as specific user ID');
        console.log('  --no-body              Suppress response body');
        console.log('  --follow-redirects     Follow redirects and show chain');
        console.log('  --headers              Display response headers');
        console.log('  --console-log          Display all console output');
        console.log('  --xhr-dump             Full XHR/fetch details');
        console.log('  --xhr-list             Simple XHR URL list');
        console.log('  --input-elements       List form inputs');
        console.log('  --post=<json>          Send POST request');
        console.log('  --cookies              Display cookies');
        console.log('  --wait-for=<selector>  Wait for element');
        console.log('  --expect-element=<sel> Verify element exists');
        console.log('  --dump-element=<sel>   Extract element HTML');
        console.log('  --storage              Display localStorage/sessionStorage');
        console.log('  --eval=<code>          Execute JavaScript');
        console.log('  --timeout=<ms>         Navigation timeout (default: 30000)');
        console.log('  --screenshot-path=<p>  Save screenshot');
        console.log('  --screenshot-width=<w> Width (px or: mobile, tablet, desktop)');
        console.log('  --full                 Enable all display options');
        process.exit(0);
    }

    const options = {
        route: null,
        user_id: null,
        no_body: false,
        follow_redirects: false,
        headers: false,
        console_log: false,
        xhr_dump: false,
        xhr_list: false,
        input_elements: false,
        post_data: null,
        cookies: false,
        wait_for: null,
        expect_element: null,
        dump_element: null,
        storage: false,
        eval_code: null,
        timeout: 30000,
        screenshot_width: null,
        screenshot_path: null,
        full: false
    };

    for (const arg of args) {
        if (arg.startsWith('--user-id=')) {
            options.user_id = arg.split('=')[1];
        } else if (arg === '--no-body') {
            options.no_body = true;
        } else if (arg === '--follow-redirects') {
            options.follow_redirects = true;
        } else if (arg === '--headers') {
            options.headers = true;
        } else if (arg === '--console-log') {
            options.console_log = true;
        } else if (arg === '--xhr-dump') {
            options.xhr_dump = true;
        } else if (arg === '--xhr-list') {
            options.xhr_list = true;
        } else if (arg === '--input-elements') {
            options.input_elements = true;
        } else if (arg.startsWith('--post=')) {
            const postData = arg.substring(7);
            try {
                options.post_data = JSON.parse(postData);
            } catch (e) {
                options.post_data = postData;
            }
        } else if (arg === '--cookies') {
            options.cookies = true;
        } else if (arg.startsWith('--wait-for=')) {
            options.wait_for = arg.substring(11);
        } else if (arg.startsWith('--expect-element=')) {
            options.expect_element = arg.substring(17);
        } else if (arg.startsWith('--dump-element=')) {
            options.dump_element = arg.substring(15);
        } else if (arg === '--storage') {
            options.storage = true;
        } else if (arg === '--full') {
            options.full = true;
        } else if (arg.startsWith('--eval=')) {
            options.eval_code = arg.substring(7);
        } else if (arg.startsWith('--timeout=')) {
            options.timeout = parseInt(arg.substring(10));
        } else if (arg.startsWith('--screenshot-width=')) {
            const width = arg.substring(19);
            options.screenshot_width = DEVICE_PRESETS[width] || parseInt(width);
        } else if (arg.startsWith('--screenshot-path=')) {
            options.screenshot_path = arg.substring(18);
        } else if (!arg.startsWith('--')) {
            options.route = arg;
        }
    }

    if (!options.route) {
        console.error('Error: Route argument required');
        process.exit(1);
    }

    if (!options.route.startsWith('/')) {
        options.route = '/' + options.route;
    }

    if (options.full) {
        options.headers = true;
        options.console_log = true;
        options.xhr_dump = true;
        options.input_elements = true;
        options.cookies = true;
        options.storage = true;
    }

    return options;
}

(async () => {
    const options = parseArgs();

    const baseUrl = process.env.BASE_URL || 'http://localhost';
    const fullUrl = baseUrl + options.route;
    const laravelLogPath = process.env.LARAVEL_LOG_PATH || '/var/www/html/storage/logs/laravel.log';

    // Launch headless browser
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const contextOptions = { ignoreHTTPSErrors: true };

    if (options.screenshot_path) {
        const width = options.screenshot_width || 1920;
        contextOptions.viewport = { width, height: 1080 };
        options.screenshot_width = width;
    }

    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();

    // Collect console output
    const consoleErrors = [];
    const consoleMessages = [];

    page.on('pageerror', error => {
        consoleErrors.push(`[UNCAUGHT] ${error.message}`);
    });

    page.on('console', msg => {
        const type = msg.type();
        const text = msg.text();

        if (type === 'error') {
            consoleErrors.push(`[ERROR] ${text}`);
        }

        if (options.console_log) {
            consoleMessages.push({ type, text });
        }
    });

    // Collect network failures
    const networkFailures = [];
    page.on('requestfailed', request => {
        networkFailures.push(request.url());
    });

    // Collect XHR requests
    const xhrRequests = [];
    if (options.xhr_dump || options.xhr_list) {
        page.on('request', request => {
            const type = request.resourceType();
            if (type === 'xhr' || type === 'fetch') {
                xhrRequests.push({
                    url: request.url(),
                    method: request.method(),
                    headers: request.headers(),
                    postData: request.postData(),
                    response: null
                });
            }
        });

        page.on('response', async response => {
            const request = response.request();
            const type = request.resourceType();
            if (type === 'xhr' || type === 'fetch') {
                const xhr = xhrRequests.find(r => r.url === request.url() && !r.response);
                if (xhr) {
                    xhr.response = {
                        status: response.status(),
                        headers: response.headers()
                    };
                    try {
                        xhr.response.body = await response.text();
                    } catch (e) {
                        // Ignore
                    }
                }
            }
        });
    }

    // Track redirects
    const redirectChain = [];
    if (options.follow_redirects) {
        page.on('response', response => {
            const status = response.status();
            if (status >= 300 && status < 400) {
                const location = response.headers()['location'];
                if (location) {
                    redirectChain.push({ url: response.url(), status, location });
                }
            }
        });
    }

    // Set up request headers
    const extraHeaders = {
        'X-Headless-Test': '1'
    };

    if (options.user_id) {
        extraHeaders['X-Dev-Auth-User-Id'] = options.user_id;
    }

    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (url.startsWith(baseUrl)) {
            await route.continue({
                headers: { ...request.headers(), ...extraHeaders }
            });
        } else {
            await route.continue();
        }
    });

    // Navigate to route
    let response;

    if (options.post_data) {
        await page.goto('about:blank');
        const fetchResult = await page.evaluate(async ({ url, data, headers }) => {
            const body = typeof data === 'object' ? JSON.stringify(data) : data;
            const contentType = typeof data === 'object' ? 'application/json' : 'application/x-www-form-urlencoded';

            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': contentType, ...headers },
                body
            });

            return {
                status: resp.status,
                headers: Object.fromEntries(resp.headers.entries()),
                body: await resp.text(),
                url: resp.url
            };
        }, { url: fullUrl, data: options.post_data, headers: extraHeaders });

        response = {
            status: () => fetchResult.status,
            headers: () => fetchResult.headers,
            url: () => fetchResult.url
        };

        if (fetchResult.headers['content-type']?.includes('text/html')) {
            await page.setContent(fetchResult.body, { waitUntil: 'networkidle' });
        }
    } else {
        const maxRetries = 3;
        for (let attempt = 1; attempt <= maxRetries; attempt++) {
            try {
                response = await page.goto(fullUrl, {
                    waitUntil: 'networkidle',
                    timeout: options.timeout
                });
                break;
            } catch (error) {
                if (error.message.includes('ERR_CONNECTION_REFUSED') && attempt < maxRetries) {
                    const delay = Math.pow(2, attempt - 1) * 1000;
                    console.error(`Connection refused, retrying in ${delay}ms...`);
                    await new Promise(r => setTimeout(r, delay));
                } else {
                    console.log(`FAIL ${fullUrl} - ${error.message}`);
                    await browser.close();
                    process.exit(1);
                }
            }
        }
    }

    if (!response) {
        console.log(`FAIL ${fullUrl} - No response`);
        await browser.close();
        process.exit(1);
    }

    // Wait for page to stabilize
    await page.evaluate(() => {
        return new Promise(resolve => {
            if (document.readyState === 'complete') {
                setTimeout(resolve, 300);
            } else {
                window.addEventListener('load', () => setTimeout(resolve, 300));
            }
        });
    });

    // Wait for specific element if requested
    if (options.wait_for) {
        try {
            await page.waitForSelector(options.wait_for, { timeout: options.timeout });
        } catch (e) {
            console.log(`Warning: Element '${options.wait_for}' not found`);
        }
    }

    // Verify element exists
    if (options.expect_element) {
        const exists = await page.evaluate(sel => document.querySelector(sel) !== null, options.expect_element);
        if (!exists) {
            console.log(`FAIL: Expected element '${options.expect_element}' not found`);
            await browser.close();
            process.exit(1);
        }
    }

    // Dump element HTML
    if (options.dump_element) {
        const html = await page.evaluate(sel => {
            const el = document.querySelector(sel);
            return el ? el.outerHTML : null;
        }, options.dump_element);

        if (html) {
            console.log(`\nElement '${options.dump_element}':`);
            console.log(html);
            console.log('');
        } else {
            console.log(`Warning: Element '${options.dump_element}' not found`);
        }
    }

    // Execute JavaScript
    if (options.eval_code) {
        try {
            const result = await page.evaluate(code => {
                try {
                    const r = eval(code);
                    if (r === undefined) return 'undefined';
                    if (r === null) return 'null';
                    if (typeof r === 'object') return JSON.stringify(r, null, 2);
                    return String(r);
                } catch (e) {
                    return `Error: ${e.message}`;
                }
            }, options.eval_code);

            console.log('\nJavaScript Result:');
            console.log(result);
            console.log('');
        } catch (e) {
            console.log(`\nJavaScript Error: ${e.message}\n`);
        }
    }

    // Get response info
    const status = response.status();
    const responseHeaders = response.headers();
    const body = await page.content();

    // Output status line
    let output = `${status} ${fullUrl}`;
    if (options.post_data) output += ' method:POST';
    if (options.user_id) output += ` user:${options.user_id}`;
    if (responseHeaders['content-type']) {
        output += ` type:${responseHeaders['content-type'].split(';')[0]}`;
    }
    if (consoleErrors.length > 0) output += ` console-errors:${consoleErrors.length}`;
    if (networkFailures.length > 0) output += ` network-failures:${networkFailures.length}`;

    console.log(output);

    // Show redirect chain
    if (options.follow_redirects && redirectChain.length > 0) {
        console.log('\nRedirect Chain:');
        redirectChain.forEach((r, i) => {
            console.log(`  ${i + 1}. ${r.status} ${r.url} -> ${r.location}`);
        });
        console.log(`  ${redirectChain.length + 1}. ${status} ${response.url()} (final)`);
    } else if ([301, 302, 303, 307, 308].includes(status) && responseHeaders['location']) {
        console.log(`Redirect Location: ${responseHeaders['location']}`);
    }

    console.log('');

    // Console output
    if (options.console_log && consoleMessages.length > 0) {
        console.log('Console Output:');
        consoleMessages.forEach(m => console.log(`  [${m.type}] ${m.text}`));
        console.log('');
    } else if (consoleErrors.length > 0) {
        console.log('Console Errors:');
        consoleErrors.forEach(e => console.log(`  ${e}`));
        console.log('');
    } else {
        console.log('Console Errors: None');
        console.log('');
    }

    // Response headers
    if (options.headers) {
        console.log('Response Headers:');
        Object.keys(responseHeaders).sort().forEach(h => {
            console.log(`  ${h}: ${responseHeaders[h]}`);
        });
        console.log('');
    }

    // Network failures
    if (networkFailures.length > 0) {
        console.log('Network Failures:');
        networkFailures.forEach(u => console.log(`  ${u}`));
        console.log('');
    }

    // XHR requests
    if ((options.xhr_dump || options.xhr_list) && xhrRequests.length > 0) {
        await new Promise(r => setTimeout(r, 500));

        if (options.xhr_list) {
            console.log('XHR/Fetch Requests:');
            xhrRequests.forEach(x => {
                const status = x.response ? x.response.status : 'pending';
                console.log(`  ${x.method} ${x.url} - ${status}`);
            });
            console.log('');
        }

        if (options.xhr_dump) {
            console.log('XHR/Fetch Details:');
            xhrRequests.forEach(x => {
                console.log(`  ${x.method} ${x.url}`);
                if (x.postData) console.log(`    Body: ${x.postData}`);
                if (x.response) {
                    console.log(`    Status: ${x.response.status}`);
                    if (x.response.body) console.log(`    Response: ${x.response.body}`);
                }
                console.log('');
            });
        }
    }

    // Input elements
    if (options.input_elements) {
        const inputs = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('input, select, textarea')).map(el => ({
                tag: el.tagName.toLowerCase(),
                type: el.type || '',
                name: el.name || '',
                id: el.id || '',
                value: el.type !== 'password' ? el.value : '[hidden]',
                required: el.required,
                disabled: el.disabled
            }));
        });

        console.log('Form Inputs:');
        if (inputs.length > 0) {
            inputs.forEach(i => {
                let desc = `  <${i.tag}`;
                if (i.type) desc += ` type="${i.type}"`;
                if (i.name) desc += ` name="${i.name}"`;
                if (i.id) desc += ` id="${i.id}"`;
                desc += '>';
                if (i.value) desc += ` value="${i.value}"`;
                if (i.required) desc += ' [required]';
                if (i.disabled) desc += ' [disabled]';
                console.log(desc);
            });
        } else {
            console.log('  None');
        }
        console.log('');
    }

    // Cookies
    if (options.cookies) {
        const cookies = await context.cookies();
        console.log('Cookies:');
        if (cookies.length > 0) {
            cookies.forEach(c => {
                console.log(`  ${c.name}: ${c.value}`);
                if (c.domain) console.log(`    Domain: ${c.domain}`);
                if (c.expires) console.log(`    Expires: ${new Date(c.expires * 1000).toISOString()}`);
            });
        } else {
            console.log('  None');
        }
        console.log('');
    }

    // Storage
    if (options.storage) {
        const storage = await page.evaluate(() => ({
            local: Object.fromEntries(Object.keys(localStorage).map(k => [k, localStorage.getItem(k)])),
            session: Object.fromEntries(Object.keys(sessionStorage).map(k => [k, sessionStorage.getItem(k)]))
        }));

        console.log('localStorage:');
        const localKeys = Object.keys(storage.local);
        if (localKeys.length > 0) {
            localKeys.forEach(k => console.log(`  ${k}: ${storage.local[k]}`));
        } else {
            console.log('  (empty)');
        }
        console.log('');

        console.log('sessionStorage:');
        const sessionKeys = Object.keys(storage.session);
        if (sessionKeys.length > 0) {
            sessionKeys.forEach(k => console.log(`  ${k}: ${storage.session[k]}`));
        } else {
            console.log('  (empty)');
        }
        console.log('');
    }

    // Response body
    if (!options.no_body) {
        console.log('Response Body:');
        if (body && body.trim()) {
            console.log(body);
        } else {
            console.log('(empty)');
            if (status === 500 && fs.existsSync(laravelLogPath)) {
                console.log('\nLaravel Log:');
                try {
                    const log = fs.readFileSync(laravelLogPath, 'utf8');
                    const lines = log.trim().split('\n').slice(-50);
                    console.log(lines.join('\n'));
                } catch (e) {
                    console.log(`Error reading log: ${e.message}`);
                }
            }
        }
    }

    // Screenshot
    if (options.screenshot_path) {
        try {
            const height = await page.evaluate(() => document.documentElement.scrollHeight);
            await page.screenshot({
                path: options.screenshot_path,
                fullPage: true,
                clip: {
                    x: 0,
                    y: 0,
                    width: options.screenshot_width,
                    height: Math.min(5000, height)
                }
            });
            console.log(`\nScreenshot saved: ${options.screenshot_path} (${options.screenshot_width}px)`);
        } catch (e) {
            console.error(`Screenshot failed: ${e.message}`);
        }
    }

    await browser.close();

    if (status >= 400 || consoleErrors.length > 0 || networkFailures.length > 0) {
        process.exit(1);
    }
    process.exit(0);
})();
