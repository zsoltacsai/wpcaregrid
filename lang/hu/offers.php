<?php

/*
|--------------------------------------------------------------------------
| Egyedi helyreállítási ajánlatok: az ügyfél oldalai és e-mailjei (magyar)
|--------------------------------------------------------------------------
*/

return [

    'title' => 'Ajánlatok',
    'lead' => 'A weboldalán végzendő munkára Önnek készített ajánlatok.',
    'none' => 'Önnek nincs ajánlata.',
    'for_website' => ':host oldalra',
    'back' => 'Vissza az ajánlatokhoz',

    'price' => 'Ár',
    'one_time' => 'egyszeri fizetés',
    'valid_until' => 'Érvényes eddig',
    'website' => 'Weboldal',
    'status' => 'Állapot',
    'paid_at' => 'Kifizetve',
    'scope' => 'Mit tartalmaz az ajánlat',
    'scope_note' => 'Ez az ajánlathoz megállapodott munka. Ezen kívül semmi nincs benne.',
    'summary' => 'Az Ön ajánlata',

    'statuses' => [
        'draft' => 'Tervezet',
        'offered' => 'Válaszra vár',
        'accepted' => 'Elfogadva, fizetésre vár',
        'declined' => 'Elutasítva',
        'expired' => 'Lejárt',
        'paid' => 'Kifizetve',
        'cancelled' => 'Visszavonva',
        'completed' => 'Elvégezve',
    ],

    'accept_lead' => 'Ha elfogadja, a feltüntetett árat egyszer fizeti meg, a Stripe biztonságos oldalán. A munka a fizetés megerősítése után indul.',
    'accept' => 'Elfogadás és fizetés',
    'decline' => 'Elutasítás',
    'confirm_decline' => 'Elutasítja ezt az ajánlatot?',
    'pay_lead' => 'Ezt az ajánlatot elfogadta. A megerősítéshez fejezze be a fizetést.',
    'pay' => 'Tovább a fizetéshez',
    'confirming' => 'Köszönjük. Arra várunk, hogy a Stripe megerősítse a fizetést. Az oldal a megerősítés után frissül; nem kell újra fizetnie.',
    'paid_note' => 'A fizetés megerősítve. Megkezdjük a fent leírt munkát.',
    'completed_note' => 'Az ajánlatban leírt munka elkészült.',
    'expired_note' => 'Ez az ajánlat már nem érvényes. Ha még szeretné a munkát, vegye fel velünk a kapcsolatot.',
    'declined_note' => 'Ezt az ajánlatot elutasította.',
    'cancelled_note' => 'Ezt az ajánlatot visszavontuk.',
    'declined' => 'Az ajánlatot elutasította.',

    'dashboard_open' => '{1} 1 ajánlat vár az Ön válaszára.|[2,*] :count ajánlat vár az Ön válaszára.',
    'dashboard_view' => 'Ajánlatok megtekintése',

    'errors' => [
        'not_acceptable' => 'Ez az ajánlat már nem fogadható el.',
        'not_payable' => 'Ez az ajánlat már nem fizethető ki.',
        'not_declinable' => 'Ez az ajánlat már nem utasítható el.',
        'not_eligible' => 'A helyreállítási ajánlat jelenleg csak a magyar számlázási piacon érhető el.',
        'payment_unavailable' => 'A fizetési oldalt nem sikerült megnyitni. Az elfogadása mentve van; kérjük, próbálja újra egy perc múlva.',
    ],

    'mail' => [
        'greeting' => 'Kedves :name!',
        'signature' => 'WP CareGrid',
        'support' => 'Kérdése van? Válaszoljon erre az e-mailre.',
        'sent' => [
            'subject' => 'Ajánlat a weboldalára',
            'intro' => 'Ajánlatot készítettünk a(z) :host oldalra.',
            'price' => 'Ár: :price (egyszeri fizetés)',
            'valid' => 'Érvényes eddig: :until',
            'body' => 'Jelentkezzen be, hogy elolvassa, mit tartalmaz az ajánlat, és elfogadja vagy elutasítsa.',
            'action' => 'Ajánlat megtekintése',
        ],
        'paid' => [
            'subject' => 'Megérkezett az ajánlat díja',
            'intro' => 'Megkaptuk az Ön :price összegű fizetését a(z) „**:title**” ajánlathoz (:host).',
            'body' => 'Megkezdjük az ajánlatban leírt munkát. Az állapotát a fiókjában követheti.',
            'action' => 'Ajánlat megtekintése',
        ],
    ],

];
