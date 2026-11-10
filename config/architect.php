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
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'group_by_module' => true,
        'separate_api' => true,
    ],

];
