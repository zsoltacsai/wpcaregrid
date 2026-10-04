<?php

/*
|--------------------------------------------------------------------------
| Főoldal szövegei (magyar)
|--------------------------------------------------------------------------
|
| A nyilvános főoldal összes szövege, a lang/en/home.php kulcsaival azonos
| szerkezetben. A márka- és terméknevek (WP CareGrid, WordPress, WooCommerce,
| Stripe) nem fordítandók; a csomagnevek (Basic, Business, Secure) is
| változatlanok. Az angol szöveg a jelentés forrása, nem szó szerinti fordítás
| alapja. Minden kulcsnak léteznie kell mindkét nyelven (HungarianContentTest).
|
*/

return [

    'meta' => [
        'title' => 'WP CareGrid – WordPress-karbantartás, mentések és biztonság',
        'description' => 'WordPress- és WooCommerce-karbantartás, mentések, biztonsági ellenőrzések és funkcionális ellenőrzés vállalkozásoknak.',
    ],

    'skip_link' => 'Ugrás a tartalomhoz',
    'home_link' => 'WP CareGrid kezdőoldal',
    'tagline' => 'Karbantartott WordPress. Működő weboldal.',
    'descriptor' => 'WordPress-karbantartás',

    // Nyelvválasztó (fejléc és ügyfél-oldalsáv).
    'language' => [
        'label' => 'Nyelv',
    ],

    'nav' => [
        'label' => 'Főmenü',
        'mobile_label' => 'Mobilmenü',
        'open_menu' => 'Menü megnyitása',
        'close_menu' => 'Menü bezárása',
        'services' => 'Szolgáltatások',
        'pricing' => 'Árak',
        'how_it_works' => 'Hogyan működik',
        'custom_development' => 'Egyedi fejlesztés',
        'faq' => 'GYIK',
        'login' => 'Belépés',
        'get_started' => 'Kezdés',
    ],

    'hero' => [
        'title' => 'WordPress-karbantartás, gond nélkül.',
        'text' => 'WordPress- és WooCommerce-karbantartás, mentések, biztonsági ellenőrzések és frissítések – a fontos frissítések után funkcionális ellenőrzéssel.',
        'primary_cta' => 'Csomagok megtekintése',
        'secondary_cta' => 'Hogyan működik',
        'trust' => [
            'contract' => 'Nincs hűségidő',
            'connection' => 'Biztonságos kapcsolat',
            'business' => 'Vállalkozásoknak',
        ],
    ],

    'services' => [
        'title' => 'Mit tartalmaz a karbantartás',
        'items' => [
            'updates' => ['title' => 'Frissítések', 'text' => 'WordPress, bővítmények és témák'],
            'backups' => ['title' => 'Mentések', 'text' => 'Fájlok és adatbázis, távoli helyen tárolva'],
            'security' => ['title' => 'Biztonság', 'text' => 'Sérülékenység- és malware-ellenőrzés'],
            'functional' => ['title' => 'Funkcionális ellenőrzés', 'text' => 'A frissítések után ellenőrizzük a fontos funkciókat'],
        ],
    ],

    'why' => [
        'eyebrow' => 'Miért kell karbantartás',
        'title' => 'A WordPress nem tartja karban magát.',
        'lead' => 'A bővítmények, a témák és maga a WordPress is folyamatosan változik. Rendszeres gondozás nélkül a kisebb problémák felhalmozódnak.',
        'items' => [
            'outdated' => ['title' => 'Elavult szoftverek', 'text' => 'A régi bővítmények és témák biztonsági és kompatibilitási problémákat okozhatnak.'],
            'backup' => ['title' => 'Nincs friss mentés', 'text' => 'Friss mentés nélkül egy hiba utáni helyreállítás sokkal nehezebb lehet.'],
            'updates' => ['title' => 'A frissítés elronthat valamit', 'text' => 'A sikeres frissítés nem mindig jelenti azt, hogy minden fontos funkció továbbra is működik.'],
        ],
    ],

    'verification' => [
        'eyebrow' => 'Frissítési folyamat',
        'title' => 'Nemcsak frissítünk. Ellenőrzünk is.',
        'lead' => 'A karbantartási folyamat része a mentés, a frissítés, a technikai ellenőrzés és a funkcionális ellenőrzés.',
        'step_label' => ':number. lépés',
        'steps' => [
            'backup' => ['title' => 'Mentés', 'text' => 'Mielőtt bármi megváltozna, friss mentés készül.', 'result' => 'Ellenőrizve'],
            'update' => ['title' => 'Frissítés', 'text' => 'A WordPress-mag, a bővítmények és a témák ellenőrzött sorrendben frissülnek.', 'result' => 'Frissítve'],
            'check' => ['title' => 'Ellenőrzés', 'text' => 'Meggyőződünk arról, hogy az oldal hibák nélkül betöltődik.', 'result' => 'Nincs hiba'],
            'functional' => ['title' => 'Funkcionális teszt', 'text' => 'A fontos oldalakat, űrlapokat és a belépést teszteljük.', 'result' => 'Sikeres'],
            'done' => ['title' => 'Kész', 'text' => 'Az eredményt rögzítjük a tevékenységnaplóban.', 'result' => 'Naplózva'],
        ],
    ],

    'pricing' => [
        'eyebrow' => 'Árak',
        'title' => 'Csomagok és árak',
        'lead' => 'Weboldalanként fix ár. Fizethet havonta, vagy évente – ekkor két hónap ingyenes. Nincs hűségidő.',
    ],

    'dashboard' => [
        'eyebrow' => 'Vezérlőpult',
        'title' => 'Minden weboldala egy helyen.',
        'lead' => 'Állapot, mentések, frissítések, biztonság és licencek minden nálunk lévő weboldalához.',
        'demo_note' => 'Szemléltető adatok, nem valódi ügyféladatok.',
        'metrics_label' => 'Példa vezérlőpult-mutatók (demóadatok)',
    ],

    'licenses' => [
        'eyebrow' => 'Licenckezelés',
        'title' => 'A lejárt licencek nem maradnak észrevétlenek.',
        'lead' => 'A prémium bővítmények és témák a licenc lejártakor nem kapnak több frissítést. Ha egy licenc lejártnak vagy érvénytelennek tűnik, a WP CareGrid rögzíti, és értesíti a weboldal tulajdonosát.',
        'limitation' => 'Nem minden bővítmény licence ellenőrizhető automatikusan; egyes gyártók nem teszik elérhetővé a licenc állapotát.',
        'responsibility' => 'A harmadik féltől származó bővítmények és témák licencei továbbra is az ügyfél felelősségi körébe tartoznak.',
    ],

    'woocommerce' => [
        'eyebrow' => 'WooCommerce',
        'title' => 'Webáruházaknál többet ellenőrzünk.',
        'lead' => 'A WooCommerce-oldalak több mozgó alkatrészből állnak, ezért a WooCommerce-csomagjaink a vásárlási folyamat körül további ellenőrzéseket is tartalmaznak.',
        'cta' => 'WooCommerce-csomagok megtekintése',
        'flow_label' => 'A frissítések után ellenőrzött vásárlási folyamat',
        'flow' => [
            'product' => 'Termék',
            'cart' => 'Kosár',
            'checkout' => 'Pénztár',
            'payment' => 'Fizetés',
            'order' => 'Rendelés',
            'email' => 'E-mail',
        ],
    ],

    'development' => [
        'eyebrow' => 'Egyedi fejlesztés',
        'title' => 'Többre van szüksége a karbantartásnál?',
        'lead' => 'Egyedi WordPress- és WooCommerce-megoldásokat is fejlesztünk.',
        'cta' => 'Beszéljünk a projektről',
        'note' => 'Az egyedi fejlesztés külön szolgáltatás: árajánlata és számlázása független a karbantartási előfizetéstől.',
        'items' => [
            'plugins' => ['title' => 'Egyedi bővítmények', 'text' => 'Egyedi funkciók a weboldalához.'],
            'api' => ['title' => 'API-integrációk', 'text' => 'A WordPress vagy a WooCommerce összekötése külső rendszerekkel.'],
            'woocommerce' => ['title' => 'WooCommerce-fejlesztés', 'text' => 'Egyedi termék-, rendelés- és pénztárfunkciók.'],
            'automation' => ['title' => 'Automatizálás', 'text' => 'Kevesebb ismétlődő technikai munka.'],
        ],
    ],

    'how' => [
        'eyebrow' => 'Hogyan működik',
        'title' => 'Négy lépésben indul a karbantartás.',
        'steps' => [
            'plan' => ['title' => 'Csomagválasztás', 'text' => 'Válasszon csomagot, és fizessen online.'],
            'connect' => ['title' => 'Weboldal csatlakoztatása', 'text' => 'Csatlakoztassa a WordPress-oldalt biztonságos kapcsolaton keresztül.'],
            'audit' => ['title' => 'Első ellenőrzés', 'text' => 'Kezdeti átvilágítás, mentés és a rendszer beállítása.'],
            'ongoing' => ['title' => 'Folyamatos karbantartás', 'text' => 'Rendszeres frissítések, mentések, ellenőrzések és felügyelet.'],
        ],
    ],

    'activity' => [
        'eyebrow' => 'Tevékenységnapló',
        'title' => 'Minden naplózva van.',
        'lead' => 'Minden mentést, frissítést és ellenőrzést rögzítünk a dátumával és az eredményével, így látható, mi történt az oldalán, és mikor.',
        'frame_title' => 'Tevékenységnapló',
        'caption' => 'Példa tevékenységnapló-bejegyzések (demóadatok).',
    ],

    'faq' => [
        'eyebrow' => 'GYIK',
        'title' => 'Gyakran ismételt kérdések',
        'contact_prompt' => 'Nem találja, amit keres?',
        'contact_link' => 'Kapcsolatfelvétel',
        'items' => [
            'password' => [
                'question' => 'Meg kell adnom a WordPress-jelszavamat?',
                'answer' => 'Nem. A weboldalt biztonságos kapcsolaton keresztül csatlakoztatja, amelyet bármikor visszavonhat. Az adminisztrátori jelszavát nem kell elküldenie nekünk.',
            ],
            'broken_update' => [
                'question' => 'Mi történik, ha egy frissítés elrontja a weboldalamat?',
                'answer' => 'A frissítések előtt mentés készül. Ha a frissítés utáni ellenőrzés hibát jelez, lehetőség szerint visszaállítjuk az utolsó működő állapotot, és kivizsgáljuk az okát, mielőtt újra megpróbálnánk a frissítést.',
            ],
            'backup_location' => [
                'question' => 'Hol tárolják a mentéseket?',
                'answer' => 'A mentéseket távoli helyen, a tárhelyszervertől elkülönítve tároljuk, így a szerverrel kapcsolatos probléma nem érinti a mentéseket.',
            ],
            'backup_frequency' => [
                'question' => 'Milyen gyakran készül mentés?',
                'answer' => 'Ez a csomagtól függ. WordPress: a Basic csomagban havonta, a Business csomagban hetente, a Secure csomagban naponta. WooCommerce: a Basic csomagban hetente, a Business és a Secure csomagban naponta. A frissítések előtt is készül mentés.',
            ],
            'license' => [
                'question' => 'Mi történik, ha egy bővítmény licence lejár?',
                'answer' => 'Ha egy licenc lejártnak vagy érvénytelennek tűnik, rögzítjük, és értesítjük Önt. A harmadik féltől származó licencek megújítása továbbra is az Ön feladata. A licenc megújításáig az adott bővítmény vagy téma nem biztos, hogy kap frissítéseket.',
            ],
            'infected' => [
                'question' => 'Mi történik, ha a weboldalam megfertőződik?',
                'answer' => 'Értesítjük, ha egy biztonsági ellenőrzés problémát talál, és Önnel egyeztetjük a következő lépéseket. A malware eltávolítása a Secure csomagokban benne van, a többi csomagnál külön árajánlatot kap rá.',
            ],
            'custom_plugins' => [
                'question' => 'Fejlesztenek egyedi bővítményeket?',
                'answer' => 'Igen. Egyedi bővítményeket, API-integrációkat és WooCommerce-funkciókat fejlesztünk, külön szolgáltatásként. Írja meg, mire van szüksége, és ajánlattal válaszolunk.',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Tartsa karbantartva a WordPress-oldalát.',
        'text' => 'Válasszon csomagot, a rutinszerű technikai munkát pedig bízza ránk.',
        'primary' => 'Csomagok megtekintése',
        'secondary' => 'Kapcsolatfelvétel',
    ],

    'footer' => [
        'columns' => [
            'product' => 'Termék',
            'account' => 'Fiók',
            'legal' => 'Jogi tudnivalók',
        ],
        'links' => [
            'contact' => 'Kapcsolat',
            'terms' => 'Szerződési feltételek',
            'privacy' => 'Adatvédelem',
            'cookies' => 'Süti-tájékoztató',
        ],
        'copyright' => '© :year WP CareGrid',
    ],

];
