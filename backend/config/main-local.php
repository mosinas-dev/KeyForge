<?php

return [
    'components' => [
        'request' => [
            // Required by cookie validation. Env-driven so a real key can be injected
            // in shared/prod (KEYFORGE_COOKIE_VALIDATION_KEY); the literal below is a
            // dev-only default for local/CI/disposable Docker — NOT a production secret.
            'cookieValidationKey' => getenv('KEYFORGE_COOKIE_VALIDATION_KEY') ?: '5qDN6eAOEYTrCWfpCYbM1gJ0vQGnLFZe',
        ],
    ],
];
