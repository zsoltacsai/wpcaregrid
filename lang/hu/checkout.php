<?php

return [

    'meta' => [
        'pricing_title' => 'Árak – WP CareGrid',
        'pricing_description' => 'WordPress- és WooCommerce-karbantartási csomagok. Fizethet havonta, vagy évente – ekkor két hónap ingyenes.',
        'checkout_title' => 'Rendelés – WP CareGrid',
        'success_title' => 'Rendelés állapota – WP CareGrid',
        'cancel_title' => 'Rendelés megszakítva – WP CareGrid',
    ],

    'breadcrumb' => 'Navigációs útvonal',
    'back_to_pricing' => 'Vissza az árakhoz',

    'title' => 'Rendelés véglegesítése',
    'lead' => 'Erősítse meg a csomagot és az adatait. A fizetést a Stripe kezeli.',
    'identity' => [
        'required' => 'A fizetés előtt adja meg a számlázási adatait.',
        'incomplete' => 'A fizetés folytatásához adja meg a kötelező számlázási adatokat.',
    ],
    'tax_note' => 'Az ár weboldalanként értendő. Ez a végleges ár; áfát nem számítunk fel.',

    'plan_heading' => 'Csomag',
    'change_plan' => 'Csomag módosítása',
    'interval_heading' => 'Számlázási időszak',
    'details_heading' => 'Az Ön adatai',
    'fields' => [
        'email' => 'E-mail-cím',
        'email_hint' => 'Ide küldjük a rendelés visszaigazolását és a weboldal csatlakoztatásához szükséges útmutatót.',
        'first_name' => 'Keresztnév',
        'last_name' => 'Vezetéknév',
        'customer_type' => 'Számlázás típusa',
        'billing_name' => 'Számlázási név',
        'company_name' => 'Cégnév',
        'tax_id' => 'Adószám (nem kötelező)',
        'address_line1' => 'Utca, házszám',
        'address_line2' => 'Cím második sora',
        'postal_code' => 'Irányítószám',
        'city' => 'Település',
        'country' => 'Számlázási ország',
        'country_hint' => 'Az ár és a pénznem a számlázási országtól függ.',
        'currency' => 'Ár pénzneme',
        'currency_hint' => 'Az elérhető pénznem a számlázási országtól függ.',
        'currency_locked' => 'Ehhez a fiókhoz :currency pénznemű aktív előfizetés tartozik. Az új előfizetésnek is :currency pénzneműnek kell lennie.',
        'promotion_code' => 'Kedvezménykód',
        'optional' => 'nem kötelező',
    ],

    'international_unavailable' => 'Ehhez a számlázási országhoz még nem érhetők el árak. Ha szeretne feliratkozni, kérjük, lépjen kapcsolatba velünk.',

    'submit' => 'Tovább a fizetéshez',
    'redirect_note' => 'A fizetési adatok megadásához átirányítjuk a Stripe oldalára.',
    'secure_note' => 'A kártyaadatokat a Stripe kezeli, azok soha nem jutnak el a mi szervereinkre.',

    'preview' => [
        'apply' => 'Kód alkalmazása',
        'checking' => 'Kedvezmény ellenőrzése…',
        'applied' => 'A kedvezményt figyelembe vettük a becslésben.',
        'regular' => 'Eredeti ár',
        'today' => 'Most fizetendő',
        'then' => 'Ezután',
        'renews' => 'Megújulási ár',
        'year' => 'év',
        'month' => 'hónap',
        'once' => 'kedvezmény az első számlázási időszakra',
        'ongoing' => 'folyamatos kedvezmény',
        'fixed_once' => 'Kedvezmény az első számlázási időszakra',
        'fixed_ongoing' => 'Folyamatos kedvezmény',
        'repeating_unknown' => 'A kedvezmény lejárati idejétől függ',
        'estimate' => 'Ez csak előzetes becslés. A kedvezményt a fizetés indításakor újra ellenőrizzük.',
        'invalid_input' => 'Ellenőrizze a kedvezmény adatait, majd próbálja újra.',
        'unavailable' => 'Ez a kedvezmény nem használható ehhez a választáshoz. Ellenőrizze a kódot, és próbálja újra.',
    ],

    'summary' => [
        'heading' => 'Rendelés összegzése',
        'billed' => [
            'monthly' => 'Havi számlázás. Nincs hűségidő.',
            'annual' => 'Éves számlázás.',
        ],
        'includes' => 'Tartalma',
        'next_heading' => 'Mi történik ezután',
        'next' => [
            'Biztonságosan fizet a Stripe-on keresztül.',
            'E-mailben visszaigazoljuk a rendelését.',
            'Elküldjük a weboldala csatlakoztatásához szükséges útmutatót.',
        ],
    ],

    'errors' => [
        'invalid_selection' => 'A kiválasztott csomag nem érhető el. Kérjük, válasszon újra csomagot.',
        'market_not_available' => 'Ehhez a számlázási országhoz még nem érhető el ár. Kérjük, vegye fel velünk a kapcsolatot.',
        'market_currency_mismatch' => 'Magyar számlázáshoz HUF szükséges. Más számlázási ország esetén EUR, USD vagy GBP választható.',
        'customer_currency_mismatch' => 'Ehhez a fiókhoz :currency pénznemű aktív előfizetés tartozik. A folytatáshoz válassza a(z) :currency pénznemet.',
        'customer_currency_ambiguous' => 'Nem tudjuk megerősíteni a fiók számlázási pénznemét. Új előfizetés előtt kérjük, lépjen kapcsolatba az ügyfélszolgálattal.',
        'price_inactive' => 'Ez a csomag jelenleg nem rendelhető meg.',
        'price_not_purchasable' => 'A csomag online rendelése még nem érhető el. A feliratkozáshoz kérjük, lépjen kapcsolatba velünk.',
        'unavailable' => 'Az online rendelés átmenetileg nem elérhető. Kérjük, próbálja újra később, vagy lépjen kapcsolatba velünk.',
    ],

    'promotion_errors' => [
        'unknown' => 'Ez a kedvezménykód nem érvényes.',
        'inactive' => 'Ez a kedvezménykód nem aktív.',
        'not_started' => 'Ez a kedvezménykód még nem érvényes.',
        'expired' => 'Ez a kedvezménykód lejárt.',
        'already_used' => 'Ezt a kedvezménykódot Ön már a legnagyobb megengedett számú alkalommal felhasználta.',
        'used_up' => 'Ezt a kedvezménykódot már a legnagyobb megengedett számú alkalommal használták fel.',
        'not_applicable' => 'Ez a kedvezménykód nem használható a kiválasztott csomaggal vagy számlázási időszakkal.',
    ],

    'success' => [
        'title' => 'Fizetés beérkezett',
        'lead' => 'Köszönjük. A rendelését megkaptuk.',
        'body' => 'E-mailben visszaigazoljuk a rendelést, amint a Stripe megerősítette a fizetést, majd elküldjük a weboldala csatlakoztatásához szükséges útmutatót. A weboldala még nincs csatlakoztatva; semmi nem változik rajta, amíg be nem fejezi a csatlakoztatást.',
        'order' => 'Rendelési azonosító',
        'plan' => 'Csomag',
        'email_note' => 'Az e-maileket erre a címre küldjük: :email.',
        'home' => 'Vissza a kezdőoldalra',
    ],

    'cancel' => [
        'title' => 'Rendelés megszakítva',
        'lead' => 'Nem történt fizetés.',
        'body' => 'Visszatérhet a rendeléshez ugyanezzel a csomaggal, vagy választhat másik csomagot.',
        'retry' => 'Vissza a rendeléshez',
        'pricing' => 'Csomagok megtekintése',
    ],

];
