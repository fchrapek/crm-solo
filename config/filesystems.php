<?php

declare(strict_types=1);

// Merged over the framework's filesystems config; only the private disk differs.
return [

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

    ],

];
