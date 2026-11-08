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
        'title' => 'WordPress-üzemeltetés és karbantartás | WP CareGrid',
        'description' => 'WordPress- és WooCommerce-frissítések, mentések, biztonsági ellenőrzések és webhelyállapot egyetlen üzemeltetési platformon.',
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
        'title' => 'A háttérben dolgozunk. Ön tisztán lát.',
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
            'backups' => ['title' => 'Mentések', 'text' => 'Mentések támogatott beállításokhoz; elkülönített tárhely, ahol be van állítva'],
            'security' => ['title' => 'Biztonság', 'text' => 'A csomag szerinti, ütemezett biztonsági ellenőrzések'],
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
        'lead' => 'A csomag és a webhely beállításainak támogatása esetén a karbantartás mentést, frissítéseket, technikai és funkcionális ellenőrzéseket is tartalmaz.',
        'step_label' => ':number. lépés',
        'steps' => [
            'backup' => ['title' => 'Mentés', 'text' => 'Frissítés előtti mentés ott készül, ahol ezt a csomag és a webhely beállítása támogatja.', 'result' => 'Ellenőrizve'],
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
        'lead' => 'A fiókjához csatlakoztatott webhelyek elérhető állapot-, mentési, frissítési, biztonsági és licencadatai.',
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
        'title' => 'Három lépésben indul a karbantartás.',
        'steps' => [
            'plan' => ['title' => 'Csomagok megismerése', 'text' => 'Tekintse át a lehetőségeket, és érdeklődjön e-mailben a webhelye szolgáltatásának elérhetőségéről.'],
            'agree' => ['title' => 'Egyeztetés', 'text' => 'Megbeszéljük a webhely igényeit, felmérjük a beállításokat, és egyeztetjük, hogyan tudunk segíteni. A mentés a csomagtól és a webhely támogatásától függ.'],
            'maintain' => ['title' => 'Folyamatos karbantartás', 'text' => 'Ahol a szolgáltatás elérhető a webhelyéhez, a karbantartás a frissítéseket, mentéseket, ellenőrzéseket és felügyeletet a csomag és a beállítás szerint fedi le.'],
        ],
    ],

    'activity' => [
        'eyebrow' => 'Tevékenységnapló',
        'title' => 'A rögzített webhelytevékenységek áttekinthetők.',
        'lead' => 'A támogatott mentések, frissítések és ellenőrzések elérhető dátummal és eredménnyel jelennek meg, így áttekintheti a webhelyén végzett műveleteket.',
        'frame_title' => 'Tevékenységnapló',
        'caption' => 'Példa tevékenységnapló-bejegyzések (demóadatok).',
    ],

    'faq' => [
        'eyebrow' => 'GYIK',
        'title' => 'Gyakran ismételt kérdések',
        'contact_prompt' => 'Nem találja, amit keres?',
        'contact_link' => 'Kapcsolatfelvétel',
        'cta' => 'Kérdésed van? Beszéljük meg!',
        'items' => [
            'includes' => [
                'question' => 'Mit tartalmaz a WordPress karbantartás?',
                'answer' => 'A csomagtól függően: WordPress-, bővítmény- és témafrissítések, mentések, biztonsági ellenőrzések, elérhetőség-figyelés támogatott webhelyeken és tevékenységnapló. A Business és a Secure csomag a fontos frissítések utáni funkcionális ellenőrzést és licencellenőrzést is tartalmaz; a Secure ezen felül elsőbbségi támogatást, havi jelentést és jogosult, támogatott esetekben malware-eltávolítást. Harmadik féltől származó licencek és egyedi fejlesztés nem tartoznak bele.',
            ],
            'why' => [
                'question' => 'Miért fontos a rendszeres WordPress karbantartás?',
                'answer' => 'A WordPress, a bővítmények és a témák folyamatosan változnak. Az elavult szoftver biztonsági és kompatibilitási problémákat okozhat, friss mentés nélkül a hibák utáni helyreállítás sokkal nehezebb, és egy sikeres frissítés sem jelenti automatikusan, hogy minden fontos funkció tovább működik. A rendszeres karbantartás csökkenti ezeket a kockázatokat, de nem szünteti meg őket.',
            ],
            'woocommerce' => [
                'question' => 'WooCommerce webáruházakat is karbantartotok?',
                'answer' => 'Igen, külön WooCommerce csomagokkal. A WordPress-karbantartás mellett a Business és a Secure csomag a frissítések után a pénztári folyamatot, valamint a fizetési és rendelési e-maileket is ellenőrzi. A mentés a Basic csomagban hetente, a Business és a Secure csomagban naponta készül, ahol a csomag és a webhely beállítása ezt támogatja.',
            ],
            'start' => [
                'question' => 'Hogyan indul az együttműködés?',
                'answer' => 'Jelenleg kapcsolatfelvétellel: írja meg a weboldala címét és az igényeit. Egyeztetjük, hogy tudunk-e segíteni, melyik csomag illik hozzá, és mit támogat a webhely beállítása. A WordPress-, a tárhely- és az adatbázisjelszavát nem kérjük el. Az online rendelés és az önkiszolgáló csatlakoztatás jelenleg nem érhető el.',
            ],
            'downtime' => [
                'question' => 'Elérhetetlenné válhat a weboldalam karbantartás közben?',
                'answer' => 'Frissítés közben a WordPress rövid időre karbantartási üzemmódba kapcsolhat, ilyenkor az oldal átmenetileg nem vagy lassabban érhető el. Ezt nem lehet teljesen kizárni, és garantált elérhetőséget nem vállalunk. A frissítés előtt mentés készül, ahol a csomag és a webhely beállítása ezt támogatja, utána pedig ellenőrizzük, hogy az oldal hiba nélkül betöltődik.',
            ],
            'failure' => [
                'question' => 'Mi történik, ha meghibásodik a weboldalam?',
                'answer' => 'Ha egy frissítés utáni ellenőrzés hibát jelez, áttekintjük az elérhető mentési és helyreállítási lehetőségeket; a visszaállítás a mentéstől és a tárhelykörnyezettől is függ. Biztonsági ellenőrzési találatról értesítjük, és egyeztetjük a következő lépéseket. Malware-eltávolítás jogosult, támogatott Secure-esetekben érhető el. Válasz- vagy helyreállítási határidőt nem ígérünk.',
            ],
            'tracking' => [
                'question' => 'Hogyan követhetem nyomon a karbantartást?',
                'answer' => 'Minden csomag része a tevékenységnapló, amely a támogatott mentések, frissítések és ellenőrzések rögzített eredményeit mutatja; a Secure csomagban havi karbantartási jelentés is jár. Az ügyfélfelület, ahol ezt követheti, jelenleg még nem nyilvánosan elérhető, ezért a részletekről a kapcsolatfelvétel során egyeztetünk.',
            ],
            'cancellation' => [
                'question' => 'Hogyan működik az előfizetés lemondása?',
                'answer' => 'Havi számlázásnál nincs hűségidő. A lemondás az előfizetés ügyfélfiókban kezelt lemondási funkciójával a már fizetett időszak végére kérhető, addig az előfizetés aktív marad. Az online rendelés és az ügyfélfiók jelenleg még nem nyilvános, ezért most a lemondásról a kapcsolatfelvétel során egyeztetünk. A részleteket a szerződési feltételek tartalmazzák.',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Tartsa karbantartva a WordPress-oldalát.',
        'text' => 'Tekintse át a csomagokat, és egyeztessünk a webhelyéről és a szolgáltatás elérhetőségéről.',
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
