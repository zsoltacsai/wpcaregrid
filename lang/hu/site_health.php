<?php

return [

    'title' => 'Weboldal állapota',

    'dimensions' => [
        'connector' => 'Kapcsolat',
        'wordpress' => 'WordPress',
        'backups' => 'Mentések',
        'updates' => 'Frissítések',
        'security' => 'Biztonság',
    ],

    'states' => [
        'healthy' => 'Rendben',
        'attention' => 'Figyelmet igényel',
        'critical' => 'Kritikus',
        'unknown' => 'Ismeretlen',
    ],

];
