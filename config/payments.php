<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    |
    | Toggle which checkout options are offered to users.
    | To remove bank transfer later: set BANK_TRANSFER_ENABLED=false
    | (or delete the bank_transfer block + BankTransferSubscriptionService).
    |
    */

    'methods' => [
        'stripe' => [
            'enabled' => env('STRIPE_ENABLED', true),
            'label' => 'Card (Stripe)',
        ],

        // Bank transfer — keep OFF in production unless intentionally used for testing.
        'bank_transfer' => [
            'enabled' => env('BANK_TRANSFER_ENABLED', false),
            'label' => 'Bank transfer (test)',
            // When true, dummy bank transfer instantly grants credits (no admin step).
            'auto_confirm' => env('BANK_TRANSFER_AUTO_CONFIRM', false),
        ],
    ],

];
