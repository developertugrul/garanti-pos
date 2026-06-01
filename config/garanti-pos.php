<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Garanti POS Environment
    |--------------------------------------------------------------------------
    |
    | "TEST" or "PROD". This defines which endpoint will be used for the requests.
    |
    */
    'mode' => env('GARANTI_POS_MODE', 'TEST'),

    /*
    |--------------------------------------------------------------------------
    | Terminal ID
    |--------------------------------------------------------------------------
    |
    | 8-digit terminal ID provided by Garanti BBVA.
    |
    */
    'terminal_id' => env('GARANTI_POS_TERMINAL_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Provision User ID
    |--------------------------------------------------------------------------
    |
    | Generally "PROVAUT" for the prov user ID.
    |
    */
    'prov_user_id' => env('GARANTI_POS_PROV_USER_ID', 'PROVAUT'),

    /*
    |--------------------------------------------------------------------------
    | Provision Password
    |--------------------------------------------------------------------------
    |
    | Password for the provision user ID.
    |
    */
    'prov_password' => env('GARANTI_POS_PROV_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Merchant ID
    |--------------------------------------------------------------------------
    |
    | Merchant ID (Üye İşyeri Numarası) provided by the bank.
    |
    */
    'merchant_id' => env('GARANTI_POS_MERCHANT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Store Key (3D Secure Password)
    |--------------------------------------------------------------------------
    |
    | Used for 3D Secure hashing.
    |
    */
    'store_key' => env('GARANTI_POS_STORE_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Currency Code
    |--------------------------------------------------------------------------
    |
    | 949 = TRY, 840 = USD, 978 = EUR
    |
    */
    'currency' => env('GARANTI_POS_CURRENCY', '949'),

    /*
    |--------------------------------------------------------------------------
    | OOS & GarantiPay Users
    |--------------------------------------------------------------------------
    |
    | Bank typically expects PROVOOS and oosuser for OOS/GarantiPay flows.
    |
    */
    'prov_oos_user_id' => env('GARANTI_POS_PROV_OOS_USER_ID', 'PROVOOS'),
    'oos_user_id' => env('GARANTI_POS_OOS_USER_ID', 'oosuser'),
];
