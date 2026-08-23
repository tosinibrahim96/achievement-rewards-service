<?php

declare(strict_types=1);

return [
    'default' => env('PAYMENT_DRIVER', 'fake'),

    'fake' => [
        'payout_account_scenario' => env('FAKE_PAYOUT_ACCOUNT_SCENARIO', 'success'),
        'transfer_scenario' => env('FAKE_TRANSFER_SCENARIO', 'success'),
        'transfer_effect_namespace' => 'default',
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
    ],

    'payout_account_lock' => [
        'seconds' => 30,
        'wait_seconds' => 5,
    ],
];
