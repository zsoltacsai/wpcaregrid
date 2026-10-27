<?php

return [
    'kinds' => [
        'backup' => 'Mentési probléma',
    ],
    'refusals' => [
        'RESTORE_ISOLATION_UNAVAILABLE' => 'A visszaállítás tiltott, mert a webhely nem jelez ellenőrzött host-izolációs szolgáltatót. A mentések továbbra is elérhetők.',
    ],
    'backups' => [
        'restore_isolation_unavailable' => 'A visszaállítás nem érhető el, mert a webhely nem jelez ellenőrzött host-izolációs szolgáltatót. A meglévő mentések továbbra is elérhetők.',
    ],
    'connector' => [
        'readiness' => [
            'title' => 'Készültségi bizonyítékok',
            'explanation' => 'Csak a korábban rögzített adatokból származó, olvasási állapot. Az ellenőrzött friss bizonyítékot jelent; nem igazolja, hogy a Connector folyamata éppen fut. A kézbesített munka nem bizonyítja a végrehajtást.',
            'activity' => 'Utolsó észlelt aktivitás',
            'heartbeat' => 'Utolsó sikeres heartbeat',
            'successful_poll' => 'Utolsó sikeres lekérdezés',
            'inventory_collected' => 'Leltár begyűjtve',
            'inventory_ingested' => 'Leltár beérkezett a platformra',
            'capability_snapshot' => 'Képesség-pillanatkép',
            'queued' => 'Sorba állított feladatok',
            'waiting' => 'Várakozó feladatok',
            'delivered' => 'Kézbesített feladatok',
            'running' => 'Futó feladatok',
            'running_unknown' => 'Ismeretlen — nincs végrehajtási bizonyíték',
            'operation_capabilities' => 'Műveletenkénti készültség',
            'states' => ['VERIFIED' => 'Ellenőrzött bizonyíték', 'STALE' => 'Elavult', 'UNKNOWN' => 'Ismeretlen', 'REVOKED' => 'Visszavont'],
            'capability_states' => ['PASS' => 'Kész', 'WARN' => 'Ellenőrizendő', 'BLOCK' => 'Tiltott', 'UNKNOWN' => 'Ismeretlen'],
            'capabilities' => ['CONNECTION_READY' => 'Kapcsolat', 'MONITORING_READY' => 'Felügyelet', 'UPDATE_READY' => 'Frissítések', 'BACKUP_READY' => 'Mentés', 'RESTORE_READY' => 'Visszaállítás', 'SECURITY_SCAN_READY' => 'Biztonsági vizsgálat', 'SECURITY_REMEDIATION_READY' => 'Biztonsági helyreállítás', 'ADMIN_ACCESS_READY' => 'Ideiglenes rendszergazdai hozzáférés'],
        ],
    ],
];
