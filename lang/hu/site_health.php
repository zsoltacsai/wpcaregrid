<?php

return [

    'title' => 'Weboldal állapota',

    'dimensions' => [
        'availability' => 'Webhely elérhetősége',
        'tls' => 'TLS-tanúsítvány',
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
