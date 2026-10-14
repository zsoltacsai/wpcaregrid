<?php

return [
    'types' => ['access' => 'Hozzáférési / exportkérelem', 'rectification' => 'Helyesbítési kérelem', 'erasure' => 'Törlési / fióklezárási kérelem'],
    'request_statuses' => [
        'requested' => 'Kérelmezve', 'identity_verification_required' => 'Személyazonosság ellenőrzése szükséges', 'verified' => 'Személyazonosság ellenőrizve',
        'review_pending' => 'Felülvizsgálatra vár', 'blocked_policy' => 'Szabályzati felülvizsgálat blokkolja', 'manual_review' => 'Kézi felülvizsgálat', 'cancelled' => 'Visszavonva',
    ],
    'provider_task_statuses' => ['pending' => 'Függőben', 'requested' => 'Kérve', 'confirmed' => 'Megerősítve', 'retained_legal' => 'Felülvizsgálatig megőrizve', 'failed' => 'Sikertelen', 'manual_review' => 'Kézi felülvizsgálat'],
    'gate_states' => ['SAFE_DISABLED_BY_POLICY' => 'BIZTONSÁGOS / SZABÁLYZAT SZERINT KIKAPCSOLVA', 'REQUIRES_REVIEW' => 'Bekapcsolva — ellenőrzés szükséges'],
    'fact_statuses' => ['unverified' => 'Nincs ellenőrizve', 'unreviewed' => 'Nincs felülvizsgálva'],
    'providers' => ['stripe' => 'Stripe', 'szamlazz' => 'Számlázz.hu', 'object_storage' => 'Objektumtár', 'mail' => 'Levelezési szolgáltató', 'hosting' => 'Tárhelyszolgáltató', 'cloudflare' => 'Cloudflare', 'other' => 'Egyéb'],
    'events' => ['created' => 'Kérelem létrehozva', 'identity_verified' => 'Személyazonosság ellenőrzése rögzítve', 'status_changed' => 'Állapot módosítva', 'provider_task_created' => 'Szolgáltatói egyeztetési feladat rögzítve', 'provider_task_status_changed' => 'Szolgáltatói feladat állapota módosítva'],
    'owner' => [
        'title' => 'Adatvédelmi kérelmek', 'lead' => 'A nem destruktív adatvédelmi ügyek csak olvasható operatív nézete.',
        'non_destructive_notice' => 'Alapozó funkció: itt nem érhető el export, törlés, szolgáltatói törlés vagy megőrzési végrehajtás.',
        'request_id' => 'Kérelemazonosító', 'customer_reference' => 'Ügyfélhivatkozás', 'type' => 'Kérelem típusa', 'status' => 'Állapot', 'requested_at' => 'Kérelmezve (UTC)',
        'reviewed_at' => 'Felülvizsgálva (UTC)', 'provider_tasks' => 'Szolgáltatói feladatok', 'provider' => 'Szolgáltató', 'scope' => 'Adatkategória', 'attempts' => 'Próbálkozások',
        'error_category' => 'Biztonságos hibakategória', 'history' => 'Ügytörténet', 'empty' => 'Nincs rögzített adatvédelmi kérelem.', 'request_detail' => 'Adatvédelmi kérelem részletei',
        'detail_lead' => 'Csak azonosítók és operatív állapot látható. Ügyféltartalom és kérelemszöveg nem jelenik meg.', 'retention_gate' => 'Destruktív megőrzési kapu',
        'no_provider_tasks' => 'Nincs rögzített szolgáltatói egyeztetési feladat.',
        'provider_registry' => 'Szolgáltatói és szerződéses tények', 'provider_registry_notice' => 'Csak technikai szolgáltatástérkép. A régió, szerződéses státusz és szolgáltatói törlési képesség felülvizsgálatig nincs ellenőrizve.',
        'service_scope' => 'Megfigyelt szolgáltatási kör', 'region_review' => 'Régió ténye', 'contract_review' => 'Szerződéses státusz', 'deletion_capability' => 'Törlési képesség',
    ],
];
