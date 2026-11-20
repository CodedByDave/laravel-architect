<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Architecture style
    |--------------------------------------------------------------------------
    | "layered"  => app/Services, app/Repositories, ...
    | "modular"  => app/Modules/{Name}/Services, ... (planned, not yet implemented)
    */
    'architecture' => 'layered',

    /*
    |--------------------------------------------------------------------------
    | Layers
    |--------------------------------------------------------------------------
    | Enable only the layers your project needs.
    */
    'layers' => [
        'services' => true,
        'repositories' => true,
        'enums' => true,
        'dtos' => true,
        'actions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    | architect:scan uses these to spot repositories/services/actions that sit
    | loose in app/Repositories etc. but belong in a domain folder. A class whose
    | name contains one of the keywords (PaymongoRepository -> "paymongo") is
    | recommended to move into that domain's folder. "label" is used in the reason.
    */
    'domains' => [
        'Payment' => [
            'label' => 'payment gateway',
            'keywords' => ['paymongo', 'stripe', 'paypal', 'gcash', 'maya', 'xendit', 'payout', 'refund'],
        ],
        'Shipping' => [
            'label' => 'shipping provider',
            'keywords' => ['courier', 'delivery', 'shipment'],
        ],
        'Notification' => [
            'label' => 'notification channel',
            'keywords' => ['sms', 'twilio', 'mailer', 'push'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report
    |--------------------------------------------------------------------------
    | Thresholds used by architect:report to flag fat controllers.
    */
    'report' => [
        'max_controller_methods' => 10,
        'max_controller_lines' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'group_by_module' => true,
        'separate_api' => true,
    ],

];
