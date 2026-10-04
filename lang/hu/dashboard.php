<?php

/*
|--------------------------------------------------------------------------
| Vezérlőpult szövegei (magyar)
|--------------------------------------------------------------------------
|
| A nyilvános oldal vezérlőpult-bemutatói használják, a lang/en/dashboard.php
| kulcsaival azonos szerkezetben. A bemutatók adatai szemléltető demóadatok.
|
*/

return [

    'demo_label' => 'Demóadatok',
    'example_label' => 'Példa',

    'preview' => [
        'compact_title' => 'Weboldalak',
        'compact_caption' => 'Példa a WP CareGrid vezérlőpultra, demóadatokkal.',
        'full_title' => 'CareGrid vezérlőpult',
        'full_caption' => 'Példa a WP CareGrid vezérlőpult áttekintésére, demóadatokkal.',
    ],

    'nav' => [
        'overview' => 'Áttekintés',
        'sites' => 'Weboldalak',
        'backups' => 'Mentések',
        'updates' => 'Frissítések',
        'security' => 'Biztonság',
        'licenses' => 'Licencek',
        'activity' => 'Tevékenységnapló',
    ],

    'sites_heading' => 'Weboldalak',
    'sites_count' => '{1} :count weboldal|[2,*] :count weboldal',

    'columns' => [
        'site' => 'Weboldal',
        'last_backup' => 'Utolsó mentés',
        'updates' => 'Frissítések',
        'security' => 'Biztonság',
        'license' => 'Licenc',
        'status' => 'Állapot',
    ],

    'platforms' => [
        'wordpress' => 'WordPress',
        'woocommerce' => 'WooCommerce',
    ],

    'days' => [
        'today' => 'Ma',
        'yesterday' => 'Tegnap',
    ],

    'backup_at' => 'Mentés: :when',

    'states' => [
        'updates' => [
            'none' => 'Naprakész',
            'pending' => ':count függőben',
        ],
        'security' => [
            'ok' => 'Nincs probléma',
            'issue' => 'Probléma található',
        ],
        'license' => [
            'valid' => 'Érvényes',
            'expired' => 'Lejárt',
            'none' => 'Nincs licenc',
        ],
    ],

    'latest_run' => 'Legutóbbi frissítési futás',
    'run_steps' => [
        'backup' => 'Mentés',
        'update' => 'Frissítés',
        'check' => 'Ellenőrzés',
        'functional' => 'Funkcionális teszt',
    ],

    'pending_updates' => 'Függőben lévő frissítések',
    'shown_count' => ':count megjelenítve',
    'licenses' => 'Licencek',
    'expired_count' => ':count lejárt',
    'update_types' => [
        'plugin' => 'Bővítmény',
        'theme' => 'Téma',
        'core' => 'Mag',
    ],

    'metrics' => [
        'backups' => ['label' => 'Sikeres mentések', 'detail' => 'Elmúlt 7 nap'],
        'updates' => ['label' => 'Függőben lévő frissítések', 'detail' => '4 weboldalon'],
        'security' => ['label' => 'Biztonsági probléma', 'detail' => 'shop.example.com'],
        'licenses' => ['label' => 'Lejárt licencek', 'detail' => 'Ügyféli teendő'],
    ],

    'license_notice' => [
        'type' => 'Bővítménylicenc',
        'website' => 'Weboldal',
        'detected' => 'Észlelve',
        'status' => 'Lejárt licenc',
        'action_title' => 'Ügyféli teendő.',
        'action_text' => 'Újítsa meg a licencet, hogy továbbra is kapjon frissítéseket.',
    ],

    'activity' => [
        'events' => [
            'backup' => 'Mentés',
            'plugin_update' => 'Bővítményfrissítés',
            'functional_check' => 'Funkcionális ellenőrzés',
            'license_check' => 'Licencellenőrzés',
        ],
        'details' => [
            'backup_stored' => 'Fájlok és adatbázis távoli helyen tárolva',
            'plugin_updated' => ':name frissítve',
            'checkout_tested' => 'Kosár, pénztár és rendelési e-mail tesztelve',
            'license_expired' => ':name – a licenc lejárt',
        ],
    ],

];
