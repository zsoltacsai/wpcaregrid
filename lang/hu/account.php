<?php

/*
|--------------------------------------------------------------------------
| Ügyfélfiók – csak a nyilvános rendelési folyamatban megjelenő szövegek (magyar)
|--------------------------------------------------------------------------
|
| A rendelés utáni oldal (/checkout/success) és a nyilvános lábléc ezeket a
| kulcsokat használja. Az ügyfélportál többi szövege még nincs lefordítva:
| a hiányzó kulcsokat a Laravel angolul adja (lang/en/account.php).
| Minden itt szereplő kulcsnak léteznie kell a lang/en/account.php-ban.
|
*/

return [

    'nav' => [
        'label' => 'Fiók',
    ],

    'dashboard' => [
        'setup_text' => 'Csatlakoztassa az első weboldalát, hogy elindíthassuk a kezdeti ellenőrzést.',
    ],

    'success' => [
        'confirming_title' => 'A fizetés megerősítése folyamatban van.',
        'confirming_body' => 'A Stripe megerősíti a fizetését. Ez általában egy percnél rövidebb ideig tart. Amint megtörtént, e-mailben értesítjük, a fiókja aktiválásához szükséges hivatkozással. Addig semmi nem aktiválódik.',
        'refresh' => 'Az oldal frissítése',
        'account_email' => 'Nézze meg az e-mailjeit: ott találja a fiókja aktiválásához szükséges hivatkozást.',
        'login' => 'Belépés a fiókba',
    ],

];
