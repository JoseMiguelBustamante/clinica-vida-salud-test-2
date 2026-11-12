<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe test credentials
    |--------------------------------------------------------------------------
    |
    | Configure these in Dokploy (or the runtime environment). Never commit
    | secret values into source control.
    |
    */

    'secret_key' => env('STRIPE_SECRET_KEY'),

    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    'currency' => env('STRIPE_CURRENCY', 'bob'),

    /*
    |--------------------------------------------------------------------------
    | Consultation fee (major units, e.g. 150.00 BOB)
    |--------------------------------------------------------------------------
    */

    'consultation_amount' => env('STRIPE_CONSULTATION_AMOUNT', '150.00'),

    'checkout_expires_minutes' => (int) env('STRIPE_CHECKOUT_EXPIRES_MINUTES', 30),

];
