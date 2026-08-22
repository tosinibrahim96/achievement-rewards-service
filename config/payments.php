<?php

declare(strict_types=1);

return [
    'default' => env('PAYMENT_DRIVER', 'fake'),

    'fake' => [
        'payout_account_scenario' => env('FAKE_PAYOUT_ACCOUNT_SCENARIO', 'success'),
    ],

    'payout_account_lock' => [
        'seconds' => 30,
        'wait_seconds' => 5,
    ],
];
