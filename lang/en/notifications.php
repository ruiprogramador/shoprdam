<?php

return [
    'kyc' => [
        'salutation' => 'With best regards, :app_name',

        'submitted' => 'The vendor :name has submitted a new KYC for review.',
        'updated'   => 'The vendor :name has updated their KYC and is awaiting review.',

        'approved' => [
            'subject'  => 'Your KYC has been approved',
            'greeting' => 'Hello, :name!',
            'message'    => 'Your identity verification (KYC) process has been approved successfully.',
            'expiration'    => 'Your verification is valid until :date.',
            'action'   => 'Go to Dashboard',
        ],

        'rejected' => [
            'subject'  => 'Your KYC has been rejected',
            'greeting' => 'Hello, :name!',
            'message'    => 'Your identity verification (KYC) process has been rejected.',
            'reason'   => 'Reason: :reason',
            'warning'    => 'Please correct the indicated issues and resubmit.',
            'action'   => 'Fix KYC',
        ],
    ],
];