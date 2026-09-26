<?php

return [

    'title' => 'Site health',

    'dimensions' => [
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
