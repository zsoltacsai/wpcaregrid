<?php

return [

    'title' => 'Site health',

    'dimensions' => [
        'availability' => 'Website availability',
        'tls' => 'TLS certificate',
        'connector' => 'Connection',
        'wordpress' => 'WordPress',
        'backups' => 'Backups',
        'updates' => 'Updates',
        'security' => 'Security',
    ],

    'states' => [
        'healthy' => 'Healthy',
        'attention' => 'Attention',
        'critical' => 'Critical',
        'unknown' => 'Unknown',
    ],

];
