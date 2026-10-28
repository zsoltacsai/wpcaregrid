<?php

/*
|--------------------------------------------------------------------------
| Árak szövegei (magyar)
|--------------------------------------------------------------------------
|
| Csomagnevek, leírások, funkciócímkék és az árazási táblázat szövege, a
| lang/en/pricing.php kulcsaival azonos szerkezetben. Hogy melyik csomag
| milyen funkciókat tartalmaz, és az összes összeg a config/pricing.php-ban
| van; ez a fájl csak a szöveget adja. A csomagnevek (Basic, Business,
| Secure) márkanevek, nem fordítjuk őket.
|
*/

return [

    'tabs_label' => 'Csomag típusa',
    'billing_label' => 'Számlázási időszak',

    // Pénznemválasztó: csak a valódi árakkal rendelkező pénznemek jelennek meg.
    'currency_label' => 'Pénznem',
    'currency_apply' => 'Alkalmaz',
    'currency_only' => 'Az árak jelenleg csak :currency pénznemben érhetők el',

    'intervals' => [
        'monthly' => 'Havi',
        'annual' => 'Éves',
    ],

    'units' => [
        'monthly' => 'hó',
        'annual' => 'év',
    ],

    'per_unit' => ':currency / :unit',
    'free_months' => '{1} :count hónap ingyenes|[2,*] :count hónap ingyenes',
    'annual_offer' => 'Éves: :offer',
    'billed_monthly' => 'Havi számlázás. Nincs hűségidő.',
    'billed_annually' => 'Éves számlázás.',
    'save' => 'Megtakarítás: :amount',
    'choose' => ':plan választása',
    'contact_for_plan' => 'Érdeklődj a csomagról',
    'everything_in' => 'A :plan csomag minden funkciója',

    'badges' => [
        'most_popular' => 'Legnépszerűbb',
    ],

    // Az árak alatt jelenik meg, a piac adókezelése szerint.
    'tax_notes' => [
        'final_price_no_vat_added' => 'Az árak magyar forintban, weboldalanként értendők. Végleges árak: áfát nem számítunk fel.',
        'undetermined' => '',
    ],
    'licenses_note' => 'A harmadik féltől származó bővítmények és témák licencei nem tartoznak bele.',

    'product_types' => [
        'wordpress' => [
            'label' => 'WordPress',
            'summary' => 'Céges weboldalakhoz, blogokhoz és landing oldalakhoz.',
        ],
        'woocommerce' => [
            'label' => 'WooCommerce',
            'summary' => 'Webáruházakhoz, a vásárlási folyamat körüli további ellenőrzésekkel.',
        ],
    ],

    'plans' => [
        'wordpress' => [
            'basic' => ['name' => 'Basic', 'description' => 'Alapvető karbantartás kisebb WordPress-oldalakhoz.'],
            'business' => ['name' => 'Business', 'description' => 'Rendszeres karbantartás céges weboldalakhoz.'],
            'secure' => ['name' => 'Secure', 'description' => 'A csomag szerinti további biztonsági ellenőrzések és jogosultsághoz kötött malware-eltávolítási támogatás.'],
        ],
        'woocommerce' => [
            'basic' => ['name' => 'Basic', 'description' => 'Alapvető karbantartás kisebb WooCommerce-webáruházakhoz.'],
            'business' => ['name' => 'Business', 'description' => 'Rendszeres karbantartás aktív WooCommerce-webáruházakhoz.'],
            'secure' => ['name' => 'Secure', 'description' => 'Bővített karbantartás és biztonsági ellenőrzések WooCommerce-webáruházakhoz.'],
        ],
    ],

    'features' => [
        'core_plugin_theme_updates' => 'WordPress-, bővítmény- és témafrissítések',
        'core_woocommerce_plugin_theme_updates' => 'WordPress-, WooCommerce-, bővítmény- és témafrissítések',
        'remote_backups' => [
            'daily' => 'Napi mentés; elkülönített tárhely, ahol be van állítva',
            'weekly' => 'Heti mentés; elkülönített tárhely, ahol be van állítva',
            'monthly' => 'Havi mentés; elkülönített tárhely, ahol be van állítva',
        ],
        'security_check' => [
            'daily' => 'Napi biztonsági ellenőrzés',
            'weekly' => 'Heti biztonsági ellenőrzés',
            'monthly' => 'Havi biztonsági ellenőrzés',
        ],
        'uptime_monitoring' => 'Elérhetőség-figyelés támogatott webhelyeken',
        'activity_log' => 'Tevékenységnapló',
        'functional_checks' => 'Funkcionális ellenőrzés a fontos frissítések után',
        'license_check' => 'Bővítmény- és témalicencek ellenőrzése',
        'checkout_flow_check' => 'Pénztári folyamat ellenőrzése frissítések után',
        'payment_email_checks' => 'Fizetési és rendelési e-mailek ellenőrzése',
        'malware_cleanup' => 'Malware-eltávolítás jogosult, támogatott esetekben',
        'priority_response' => 'Elsőbbségi támogatás a csomag szerint',
        'monthly_report' => 'Havi karbantartási jelentés',
    ],

];
