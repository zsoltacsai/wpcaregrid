<?php

/*
| A biztonsági és malware-motor szövegei, az operátori konzol és az ügyfélfelület közös fájlja.
| Az ügyfélszövegek elmagyarázzák, mit kell tenni; soha nem mutatnak szabályokat, bizonyítékot
| vagy fájltartalmat. Az operátori felület magyar nyelvű változata nincs: az angolt használja.
*/
return [

    'scan_types' => [
        'vulnerability' => 'Sérülékenységi vizsgálat',
        'integrity' => 'Integritásvizsgálat',
        'malware' => 'Malware-vizsgálat',
        'full' => 'Teljes vizsgálat',
    ],

    'scan_statuses' => [
        'queued' => 'Sorban áll',
        'running' => 'Fut',
        'completed' => 'Befejezve',
        'partial' => 'Részleges',
        'failed' => 'Sikertelen',
        'cancelled' => 'Megszakítva',
    ],

    'coverage' => [
        'completed' => 'Befejezve',
        'partial' => 'Részleges',
        'unavailable' => 'Nem elérhető',
        'failed' => 'Sikertelen',
    ],

    'scanners' => [
        'vulnerability' => 'Sérülékenységek',
        'integrity' => 'Integritás',
        'malware' => 'Malware-jelek',
    ],

    'integrity_counts' => [
        'verified' => 'ellenőrizve',
        'modified' => 'módosult',
        'missing' => 'hiányzó',
        'extra' => 'többlet',
        'unavailable' => 'nem ellenőrizhető',
    ],

    'unavailable_reasons' => [
        'VULNERABILITY_SOURCE_NOT_CONFIGURED' => 'A sérülékenységi vizsgáló nincs beállítva.',
        'NO_INVENTORY' => 'A weboldal még nem jelentette a bővítményeit és témáit.',
    ],

    'finding_types' => [
        'vulnerability' => 'Sérülékenység',
        'integrity' => 'Integritás',
        'suspicious_file' => 'Gyanús fájl',
        'suspicious_database' => 'Gyanús adatbázis-bejegyzés',
        'suspicious_user' => 'Gyanús felhasználó',
        'suspicious_cron' => 'Gyanús ütemezett feladat',
        'configuration' => 'Beállítás',
    ],

    'finding_statuses' => [
        'open' => 'Nyitott',
        'confirmed' => 'Megerősített',
        'quarantined' => 'Karanténban',
        'remediated' => 'Elhárítva',
        'false_positive' => 'Téves riasztás',
        'ignored' => 'Figyelmen kívül hagyva',
        'resolved' => 'Megoldva',
    ],

    'severities' => [
        'low' => 'Alacsony',
        'medium' => 'Közepes',
        'high' => 'Magas',
        'critical' => 'Kritikus',
        'unrated' => 'Nincs besorolva',
    ],

    'confidences' => [
        'low' => 'Alacsony megbízhatóság',
        'medium' => 'Közepes megbízhatóság',
        'high' => 'Magas megbízhatóság',
        'confirmed' => 'Megerősített',
    ],

    'incident_statuses' => [
        'open' => 'Nyitott',
        'investigating' => 'Kivizsgálás alatt',
        'containment' => 'Elszigetelés',
        'remediation' => 'Elhárítás',
        'verification' => 'Ellenőrzés',
        'resolved' => 'Megoldva',
        'closed' => 'Lezárva',
    ],

    'remediation_types' => [
        'quarantine_file' => 'Fájl karanténba helyezése',
        'restore_quarantined_file' => 'Karantén visszavonása',
        'repair_core_file' => 'Tiszta WordPress-fájl visszaállítása',
        'repair_plugin_file' => 'Tiszta bővítményfájl visszaállítása',
        'disable_plugin' => 'Bővítmény kikapcsolása',
    ],

    'remediation_statuses' => [
        'awaiting_backup' => 'Várakozás a mentésre',
        'queued' => 'Sorban áll',
        'running' => 'Fut',
        'completed' => 'Befejezve',
        'failed' => 'Sikertelen',
        'blocked' => 'Blokkolva: mentés szükséges',
        'cancelled' => 'Megszakítva',
    ],

    'states' => [
        'healthy' => 'Rendben',
        'attention' => 'Figyelmet igényel',
        'critical' => 'Kritikus',
        'unknown' => 'Ismeretlen',
    ],

    // Ügyfélfelület: mit jelent, és mi történik ezután. Nincsenek szabálynevek, útvonalak vagy bizonyítékok.
    'customer' => [
        'title' => 'Biztonság',
        'last_scan' => 'Utolsó vizsgálat',
        'never' => 'Még nem volt vizsgálat',
        'open_findings' => 'Nyitott megállapítások',
        'states' => [
            'healthy' => 'A legutóbbi vizsgálat nem talált nyitott biztonsági megállapítást.',
            'attention' => 'Találtunk valamit, ami megér egy pillantást. A csapatunk minden megállapítást átnéz.',
            'critical' => 'Súlyos biztonsági problémát találtunk. A csapatunk foglalkozik vele, és felveszi Önnel a kapcsolatot.',
            'unknown' => 'A biztonsági állapot jelenleg nem ismert: a legutóbbi vizsgálat nem fejeződött be, vagy túl régi.',
        ],
        'not_scanned' => 'A biztonsági vizsgálat még nincs beállítva.',
        'issues' => '{1} :count probléma igényel figyelmet.|[2,*] :count probléma igényel figyelmet.',
        'types' => [
            'vulnerability' => 'Ismert sérülékenységek a telepített szoftverekben. A frissítés általában megoldja őket.',
            'integrity' => 'A hivatalos változattól eltérő fájlok.',
            'suspicious_file' => 'Rosszindulatú kód jeleit mutató fájlok, a csapatunk átvizsgálja őket.',
            'suspicious_database' => 'Beinjektált kód jeleit mutató adatbázis-bejegyzések, a csapatunk átvizsgálja őket.',
            'suspicious_user' => 'Adminisztrátori fiókok, amelyeket érdemes újra ellenőrizni.',
            'suspicious_cron' => 'Ütemezett feladatok, amelyeket érdemes újra ellenőrizni.',
            'configuration' => 'A biztonságot gyengítő beállítások.',
        ],
        'vulnerability_item' => ':name :version: frissítsen erre: :patched',
        'vulnerability_item_unpatched' => ':name :version: még nincs javított verzió',
        'not_configured' => 'A sérülékenység-ellenőrzés még nincs beállítva, ezért nem része a vizsgálatnak.',
        'disclaimer' => 'A vizsgálat az ismert problémákat és figyelmeztető jeleket találja meg. Azt nem tudja bizonyítani, hogy egy weboldal teljesen biztonságos.',
    ],

];
