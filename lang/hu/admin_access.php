<?php

return [

    'levels' => [
        'maintenance' => 'Karbantartói hozzáférés',
        'full_admin' => 'Ideiglenes teljes adminisztrátori hozzáférés',
    ],

    'statuses' => [
        'pending' => 'Megnyitás',
        'authorized' => 'Indítás',
        'active' => 'Aktív',
        'expired' => 'Lejárt',
        'revoked' => 'Visszavonva',
        'failed' => 'Sikertelen',
    ],

    'refusals' => [
        'NOT_AUTHORIZED' => 'Nem jogosult a weboldal adminisztrációs felületének megnyitására.',
        'FULL_ADMIN_REQUIRES_CUSTOMER' => 'A teljes adminisztrátori hozzáféréshez az ügyfél saját megerősítése kell.',
        'SUBSCRIPTION_INACTIVE' => 'Az admin-hozzáféréshez aktív előfizetés szükséges.',
        'NOT_CONNECTED' => 'A weboldalnak csatlakoztatva kell lennie a WP CareGridhez.',
        'CONNECTOR_OFFLINE' => 'A weboldal jelenleg nem jelentkezik be. Ellenőrizze, hogy elérhető-e, és próbálja újra.',
        'CAPABILITY_MISSING' => 'Az admin-hozzáférés használatához frissítse a WP CareGrid Connector bővítményt a weboldalán.',
        'LEVEL_NOT_ALLOWED' => 'A csomagja nem tartalmazza ezt a hozzáférési szintet.',
        'CONFIRMATION_REQUIRED' => 'A teljes adminisztrátori hozzáféréshez kifejezett megerősítésére van szükség.',
        'TOO_MANY_REQUESTS' => 'Az admin-hozzáférést a közelmúltban túl gyakran nyitották meg. Kérjük, várjon egy kicsit.',
        'default' => 'Az admin-hozzáférés jelenleg nem lehetséges.',
    ],

    'account' => [
        'panel' => 'WordPress-admin',
        'lead' => 'Nyissa meg a WordPress-adminját jelszó megosztása nélkül. A hozzáférés ideiglenes, és bármikor visszavonható.',
        'none' => 'Nincs aktív admin-munkamenet',
        'active' => 'Az admin-hozzáférés aktív',
        'opening' => 'Várakozás a weboldalra',
        'expires_in' => 'Lejár: :minutes perc múlva',
        'expires_soon' => 'Egy percen belül lejár',
        'maintenance_title' => 'Karbantartói hozzáférés',
        'maintenance_text' => 'Frissítések és hibaelhárítás. Nincs felhasználókezelés, kódszerkesztés és bővítmény-feltöltés. :minutes perc.',
        'full_title' => 'Ideiglenes teljes adminisztrátori hozzáférés',
        'full_text' => 'Teljes adminisztrátori jogok :minutes percre. Csak akkor, ha a karbantartói hozzáférés nem elég.',
        'open' => 'Admin megnyitása',
        'enable_full' => 'Ideiglenes teljes admin-hozzáférés engedélyezése',
        'revoke' => 'Hozzáférés visszavonása',
        'revoked' => 'A hozzáférés visszavonva. A WordPress-munkamenet a weboldal következő bejelentkezésekor véget ér, legkésőbb a lejáratakor.',
        'no_password' => 'WordPress-jelszót nem tárolunk és nem osztunk meg: a connector rövid, külön munkamenetet hoz létre.',
    ],

    'confirm' => [
        'title' => 'Ideiglenes teljes adminisztrátori hozzáférés',
        'question' => 'Megadja a teljes adminisztrátori hozzáférést :minutes percre?',
        'warning' => 'A teljes adminisztrátori hozzáférés a weboldalon mindent módosíthat, a felhasználókat és a beállításokat is. Használja a karbantartói hozzáférést, hacsak nincs szüksége többre.',
        'points' => [
            'A hozzáférés :minutes perc után automatikusan megszűnik.',
            'Ezen az oldalon bármikor visszavonhatja.',
            'Minden lépés rögzítésre kerül a weboldal tevékenységnaplójában.',
        ],
        'checkbox' => 'Engedélyezem az ideiglenes teljes adminisztrátori hozzáférést ehhez: :host.',
        'submit' => 'Teljes admin megadása :minutes percre',
        'cancel' => 'Mégse',
        'not_possible' => 'A teljes admin-hozzáférés most nem lehetséges',
    ],

    'opening' => [
        'title' => 'A WordPress-admin megnyitása…',
        'text' => 'Bejelentkeztetjük ide: :host. Ez eltart egy pillanatig.',
        'continue' => 'Tovább a WordPress-adminhoz',
        'noscript' => 'A folytatáshoz nyomja meg a gombot.',
    ],

    'events' => [
        'requested' => 'Admin-hozzáférés kérve',
        'authorized' => 'Admin-hozzáférés engedélyezve',
        'started' => 'Admin-munkamenet elindult',
        'expired' => 'Admin-hozzáférés lejárt',
        'revoked' => 'Admin-hozzáférés visszavonva',
        'failed' => 'Admin-hozzáférés sikertelen',
    ],

];
