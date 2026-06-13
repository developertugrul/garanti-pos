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
    | Terminal Users
    |--------------------------------------------------------------------------
    |
    | Garanti GVP uses different provision users per transaction family.
    | PROVAUT is used for regular XML/3D auth flows, PROVRFN for void/refund,
    | and PROVOOS for OOS/GarantiPay/CUSTOM_PAY form flows.
    |
    */
    'prov_user_id' => env('GARANTI_POS_PROV_USER_ID', 'PROVAUT'),
    'terminal_user_id' => env('GARANTI_POS_TERMINAL_USER_ID', env('GARANTI_POS_PROV_USER_ID', 'PROVAUT')),

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
    | Refund/Void User
    |--------------------------------------------------------------------------
    |
    | Garanti examples use PROVRFN for void/refund operations. If the refund
    | password is not provided, the regular provision password is used.
    |
    */
    'refund_user_id' => env('GARANTI_POS_REFUND_USER_ID', 'PROVRFN'),
    'refund_password' => env('GARANTI_POS_REFUND_PASSWORD', env('GARANTI_POS_PROV_PASSWORD', '')),

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
    | The 3D/OOS form hash must be calculated with the password belonging to
    | the terminal provision user sent in terminalprovuserid.
    | Legacy Help/GVP OOS examples use PROVOOS. Current developer portal OOS
    | examples usually use PROVAUT; set GARANTI_POS_OOS_FORM_CREDENTIAL_ROLE=aut
    | for that flow.
    |
    */
    'prov_oos_user_id' => env('GARANTI_POS_PROV_OOS_USER_ID', 'PROVOOS'),
    'prov_oos_password' => env('GARANTI_POS_PROV_OOS_PASSWORD', env('GARANTI_POS_PROV_PASSWORD', '')),
    'oos_user_id' => env('GARANTI_POS_OOS_USER_ID', 'oosuser'),
    'oos_form_credential_role' => env('GARANTI_POS_OOS_FORM_CREDENTIAL_ROLE', 'oos'),

    /*
    |--------------------------------------------------------------------------
    | API Details
    |--------------------------------------------------------------------------
    |
    | The bundled Help/GVP samples use the legacy SHA1 hash flow. The current
    | Garanti BBVA developer portal uses SHA512 HashData with Version=512.
    | Set GARANTI_POS_HASH_ALGORITHM=sha512 only when your bank documents or
    | terminal definition expect the current portal hash structure.
    |
    */
    'api_version' => env('GARANTI_POS_API_VERSION', 'v0.01'),
    'channel_code' => env('GARANTI_POS_CHANNEL_CODE', ''),
    'hash_algorithm' => env('GARANTI_POS_HASH_ALGORITHM', 'sha1'),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    */
    'test_endpoint' => env('GARANTI_POS_TEST_ENDPOINT', 'https://sanalposprovtest.garanti.com.tr/VPServlet'),
    'prod_endpoint' => env('GARANTI_POS_PROD_ENDPOINT', 'https://sanalposprov.garanti.com.tr/VPServlet'),
    'test_3d_endpoint' => env('GARANTI_POS_TEST_3D_ENDPOINT', 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine'),
    'prod_3d_endpoint' => env('GARANTI_POS_PROD_3D_ENDPOINT', 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'),
];
