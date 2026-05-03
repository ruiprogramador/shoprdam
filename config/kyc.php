<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KYC Storage Disk
    |--------------------------------------------------------------------------
    | The disk where KYC documents are stored.
    | Use 'private' for local private storage or 's3' for AWS S3.
    */
    'storage_disk' => env('KYC_STORAGE_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | KYC Expiration Years
    |--------------------------------------------------------------------------
    | Number of years before an approved KYC expires.
    */
    'expiration_years' => env('KYC_EXPIRATION_YEARS', 1),
];