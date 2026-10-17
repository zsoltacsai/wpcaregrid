<?php

return [
    'title' => 'Webhely-képességellenőrzések',
    'lead' => 'Az ellenőrzések megmutatják, hogy a webhelyről származó aktuális adatok mely műveleteket támasztják alá. Nem garantálják a helyreállítást, és önmagukban nem engedélyeznek műveletet.',
    'status' => ['PASS' => 'Készen áll', 'WARN' => 'Megjegyzéssel készen áll', 'BLOCK' => 'Nem érhető el', 'UNKNOWN' => 'Ellenőrzés szükséges'],
    'capabilities' => [
        'CONNECTION_READY' => 'Connector-kapcsolat', 'MONITORING_READY' => 'Felügyelet', 'UPDATE_READY' => 'Frissítések',
        'BACKUP_READY' => 'Biztonsági mentések', 'RESTORE_READY' => 'Visszaállítási készenlét', 'SECURITY_SCAN_READY' => 'Biztonsági vizsgálat lehetősége',
        'SECURITY_REMEDIATION_READY' => 'Biztonsági helyreállítás', 'ADMIN_ACCESS_READY' => 'Ideiglenes adminisztrátori hozzáférés',
    ],
    'reasons' => [
        'CHECK_REQUIRED' => 'Új, aktuális ellenőrzés szükséges.', 'FACT_STALE' => 'Az előző ellenőrzés elavult. Indítson új ellenőrzést.',
        'CONNECTOR_OFFLINE' => 'A webhely Connectora jelenleg nem jelentkezik.', 'CONNECTOR_UNSUPPORTED' => 'Frissítse a webhely Connectorát egy támogatott kiadásra.',
        'CONNECTOR_UPDATE_RECOMMENDED' => 'Újabb Connector-kiadás érhető el.', 'CONNECTOR_NEWER_UNKNOWN' => 'Ezt az újabb Connector-verziót még nem értékeltük.',
        'RUNTIME_VERSION_UNSUPPORTED' => 'A PHP- vagy WordPress-verzió a megadott minimum alatt van.', 'RUNTIME_VERSION_UNTESTED' => 'Ezt a PHP- vagy WordPress-verziót még nem fedik le az aktuális kompatibilitási tesztek.',
        'PHP_EXTENSION_MISSING' => 'A művelethez szükséges PHP-bővítmény nem érhető el.',
        'CONNECTOR_CAPABILITY_MISSING' => 'A telepített Connector nem támogatja ezt a műveletet.', 'FACT_MISSING' => 'A webhely még nem küldte el az ellenőrzéshez szükséges adatokat.',
        'FACT_MALFORMED' => 'A webhely adatait nem sikerült ellenőrizni.', 'MULTISITE_UNSUPPORTED' => 'Ez a művelet WordPress Multisite esetén nem támogatott.',
        'CUSTOM_LAYOUT_UNSUPPORTED' => 'Ez a webhelyelrendezés ehhez a művelethez nem támogatott.', 'CANONICAL_ROOT_UNKNOWN' => 'A webhely fájlelrendezése nem állapítható meg.',
        'ROOT_SYMLINK_UNRESOLVED' => 'A webhely fájlelrendezése nem ellenőrizhető biztonságosan.', 'FILESYSTEM_READ_UNAVAILABLE' => 'A szükséges webhelyfájlok nem olvashatók.',
        'FILESYSTEM_WRITE_UNAVAILABLE' => 'A szükséges webhelyfájlok nem írhatók.', 'FILESYSTEM_RENAME_UNAVAILABLE' => 'A fájlok biztonságos cseréje nem ellenőrizhető.',
        'DISK_SPACE_INSUFFICIENT' => 'Nincs elegendő ideiglenes tárhely.', 'DISK_SPACE_UNKNOWN' => 'Az ideiglenes tárhely nem ellenőrizhető.',
        'DB_ENGINE_UNSUPPORTED' => 'Az adatbázismotor ehhez a művelethez nem támogatott.', 'DB_VERSION_UNKNOWN' => 'Az adatbázis-kompatibilitás nem igazolható.',
        'BACKUP_NOT_VERIFIED' => 'Nem áll rendelkezésre ellenőrzött helyreállítási pont.', 'BACKUP_OBJECT_MISSING' => 'Egy szükséges helyreállítási fájl hiányzik.',
        'RESTORE_CONFLICT' => 'A webhelyen egy másik művelet van folyamatban.', 'SCANNER_UNAVAILABLE' => 'A szükséges vizsgálati képesség nem érhető el.',
        'SCANNER_NOT_RUN' => 'Vizsgálat még nem futott.', 'SAFETY_GATE_CLOSED' => 'Egy szükséges biztonsági feltétel nem teljesül.',
    ],
    'last_checked' => 'Utolsó ellenőrzés', 'not_checked' => 'Még nem ellenőrzött', 'operator_details' => 'Technikai részletek',
    'connector_version' => 'Connector-verzió', 'wordpress_version' => 'WordPress-verzió', 'php_version' => 'PHP-verzió',
    'policy_version' => 'Szabályzat / értékelő', 'layout' => 'Észlelt elrendezés', 'disk_free' => 'Elérhető ideiglenes tárhely',
    'request_check' => 'Webhely-kompatibilitás ellenőrzése', 'check_requested' => 'A webhelyellenőrzés sorba került.',
    'check_pending' => 'Már vár egy webhelyellenőrzés a Connectorra.', 'check_unavailable' => 'Az ellenőrzés nem indítható. Ellenőrizze a Connector-kapcsolatot és az aktív szolgáltatást.',
];
