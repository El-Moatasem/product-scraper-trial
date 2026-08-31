<?php

return [
    'allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env(
            'SCRAPER_ALLOWED_HOSTS',
            'amazon.com,amazon.co.uk,amazon.eg,jumia.com,jumia.com.eg,jumia.co.ke,jumia.com.ng',
        )),
    ))),

    'timeout_seconds' => (float) env('SCRAPER_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (float) env('SCRAPER_CONNECT_TIMEOUT_SECONDS', 5),
    'max_attempts' => max(1, (int) env('SCRAPER_MAX_ATTEMPTS', 2)),
    'max_response_bytes' => (int) env('SCRAPER_MAX_RESPONSE_BYTES', 2 * 1024 * 1024),

    'proxy_manager' => [
        'url' => rtrim((string) env('PROXY_MANAGER_URL', 'http://localhost:8081'), '/'),
        'token' => (string) env('PROXY_MANAGER_TOKEN', ''),
        'required' => filter_var(env('PROXY_MANAGER_REQUIRED', false), FILTER_VALIDATE_BOOL),
    ],

    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
    ],
];
