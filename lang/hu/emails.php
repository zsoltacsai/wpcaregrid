<?php

return [
    'greeting' => 'Kedves :name!',
    'signature' => 'WP CareGrid',
    'lifecycle' => [
        'action' => 'Számlázás megtekintése',
        'cancellation_scheduled' => ['subject' => 'Az előfizetés lemondása ütemezve', 'body' => 'A(z) :plan előfizetése a kifizetett időszak végéig aktív marad, utána lezárul.'],
        'subscription_reactivated' => ['subject' => 'Az előfizetés újra aktiválva', 'body' => 'A(z) :plan előfizetés aktívan tartására vonatkozó kérését megerősítettük.'],
        'upgrade_completed' => ['subject' => 'A csomagváltás megerősítve', 'body' => 'A(z) :plan csomagváltást a sikeres fizetés után megerősítettük.'],
        'downgrade_scheduled' => ['subject' => 'A csomagváltás ütemezve', 'body' => 'A(z) :plan (:interval) csomagváltás a következő megújuláskor lép életbe. Addig a jelenlegi hozzáférése marad érvényben.'],
        'payment_recovered' => ['subject' => 'A fizetési probléma megoldódott', 'body' => 'A(z) :plan előfizetés fizetését megerősítettük, a szolgáltatáshoz való hozzáférés aktív.'],
        'grace_started' => ['subject' => 'Teendő az előfizetés fizetésével kapcsolatban', 'body' => 'A(z) :plan díját nem tudtuk levonni. A türelmi időszak alatt a fiókja továbbra is elérhető.'],
        'grace_reminder' => ['subject' => 'Emlékeztető: teendő az előfizetés fizetésével kapcsolatban', 'body' => 'Kérjük, a türelmi időszak vége előtt frissítse a(z) :plan fizetési módját.'],
        'invoice_available' => ['subject' => 'A számlája elérhető', 'body' => 'A(z) :plan számlázási bizonylata elérhető a fiókjában.'],
        'billing_action_required' => ['subject' => 'Teendő szükséges a számlázással kapcsolatban', 'body' => 'Kérjük, tekintse át a(z) :plan számlázási adatait a fiókjában.'],
    ],
    'billing_intervals' => ['monthly' => 'havi', 'annual' => 'éves'],
];
