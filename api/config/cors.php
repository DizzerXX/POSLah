<?php

/**
 * Cross-origin access.
 *
 * `broadcasting/auth` is here for a specific reason: Laravel's default paths cover `api/*`
 * only, so the browser blocked the channel-authorisation preflight and Echo silently never
 * subscribed. The socket connected, the handshake succeeded, and no ticket ever arrived —
 * a failure that looked exactly like the socket working and the server having nothing to say.
 *
 * It only stayed invisible because the KDS polls regardless, which is the design working as
 * intended and also what hid the bug.
 */
return [
    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // The two first-party front ends. Not '*': these routes carry bearer tokens, and a
    // wildcard origin on an authenticated endpoint is worth being deliberate about.
    'allowed_origins' => [
        'http://127.0.0.1:4300',
        'http://localhost:4300',
        'http://127.0.0.1:4400',
        'http://localhost:4400',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer tokens, not cookies — there is no session to carry.
    'supports_credentials' => false,
];
