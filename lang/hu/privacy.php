<?php

return [
    'types' => ['access' => 'Hozzáférési / exportkérelem', 'rectification' => 'Helyesbítési kérelem', 'account_closure' => 'Fióklezárási kérelem', 'erasure' => 'Adattörlési kérelem'],
    'request_statuses' => [
        'requested' => 'Kérelmezve', 'identity_verification_required' => 'Személyazonosság ellenőrzése szükséges', 'verified' => 'Személyazonosság ellenőrizve',
        'cooling_off' => 'Elállási időszak', 'retention_analysis' => 'Megőrzési elemzés (csak jelentés)', 'review_pending' => 'Felülvizsgálatra vár', 'blocked_policy' => 'Szabályzati felülvizsgálat blokkolja', 'blocked_legal' => 'Jogi felülvizsgálatra vár', 'blocked_accounting' => 'Könyvelői felülvizsgálatra vár', 'access_restricted' => 'Ügyfél-hozzáférés korlátozva', 'manual_review' => 'Kézi felülvizsgálat', 'failed' => 'Sikertelen', 'completed' => 'Befejezve', 'cancelled' => 'Visszavonva',
    ],
    'provider_task_statuses' => ['pending' => 'Függőben', 'requested' => 'Kérve', 'confirmed' => 'Megerősítve', 'retained_legal' => 'Felülvizsgálatig megőrizve', 'failed' => 'Sikertelen', 'manual_review' => 'Kézi felülvizsgálat', 'unknown' => 'Ismeretlen; kézi egyeztetés szükséges'],
    'gate_states' => ['SAFE_DISABLED_BY_POLICY' => 'BIZTONSÁGOS / SZABÁLYZAT SZERINT KIKAPCSOLVA', 'REQUIRES_REVIEW' => 'Bekapcsolva — ellenőrzés szükséges'],
    'fact_statuses' => ['unverified' => 'Nincs ellenőrizve', 'unreviewed' => 'Nincs felülvizsgálva'],
    'providers' => ['stripe' => 'Stripe', 'szamlazz' => 'Számlázz.hu', 'object_storage' => 'Objektumtár', 'mail' => 'Levelezési szolgáltató', 'hosting' => 'Tárhelyszolgáltató', 'cloudflare' => 'Cloudflare', 'other' => 'Egyéb'],
    'events' => ['created' => 'Kérelem létrehozva', 'identity_verified' => 'Személyazonosság ellenőrzése rögzítve', 'status_changed' => 'Állapot módosítva', 'account_access_restricted' => 'Ügyfélfelület-hozzáférés korlátozva', 'account_access_restored' => 'Ügyfélfelület-hozzáférés visszaállítva', 'provider_task_created' => 'Szolgáltatói egyeztetési feladat rögzítve', 'provider_task_status_changed' => 'Szolgáltatói feladat állapota módosítva', 'export_requested' => 'Ügyfél-export kérve', 'export_queued' => 'Export-előkészítés sorba állítva', 'export_generating' => 'Export-előkészítés elindult', 'export_generated' => 'Titkosított export elkészült', 'export_failed' => 'Export-előkészítés sikertelen', 'export_cleanup_failed' => 'Az ideiglenes export tisztítása felülvizsgálatot igényel', 'export_downloaded' => 'Export letöltve', 'export_link_renewed' => 'Letöltési hivatkozás megújítva', 'export_revoked' => 'Exportcsomag visszavonva', 'export_expired' => 'Az ideiglenes export lejárt'],
    'owner' => [
        'title' => 'Adatvédelmi kérelmek', 'lead' => 'A nem destruktív adatvédelmi ügyek csak olvasható operatív nézete.',
        'non_destructive_notice' => 'Az Owner kezelheti az ügyeket és láthatja a titkosított ügyfél-export állapotát. Adattörlés, szolgáltatói törlés és forrásadatokon végzett megőrzési művelet továbbra sem érhető el.',
        'request_id' => 'Kérelemazonosító', 'customer_reference' => 'Ügyfélhivatkozás', 'type' => 'Kérelem típusa', 'status' => 'Állapot', 'requested_at' => 'Kérelmezve (UTC)',
        'reviewed_at' => 'Felülvizsgálva (UTC)', 'provider_tasks' => 'Szolgáltatói feladatok', 'provider' => 'Szolgáltató', 'scope' => 'Adatkategória', 'attempts' => 'Próbálkozások',
        'error_category' => 'Biztonságos hibakategória', 'history' => 'Ügytörténet', 'empty' => 'Nincs rögzített adatvédelmi kérelem.', 'request_detail' => 'Adatvédelmi kérelem részletei',
        'detail_lead' => 'Csak azonosítók és operatív állapot látható. Ügyféltartalom és kérelemszöveg nem jelenik meg.', 'retention_gate' => 'Destruktív megőrzési kapu',
        'no_provider_tasks' => 'Nincs rögzített szolgáltatói egyeztetési feladat.',
        'export' => 'Ügyfél-exportcsomag', 'export_status' => 'Csomag állapota', 'export_coverage' => 'Lefedettség', 'export_partial' => 'Részleges — egyes adatkategóriák felülvizsgálat alatt kimaradnak', 'export_size' => 'Titkosított csomag mérete', 'export_generated_at' => 'Létrehozva (UTC)', 'export_expires_at' => 'Csomag lejárata (UTC)', 'export_link_expires_at' => 'Ügyfélhivatkozás lejárata (UTC)', 'export_download_count' => 'Hitelesített letöltések', 'export_failure_code' => 'Biztonságos hibakód', 'export_included' => 'Tartalmazott részek', 'export_excluded' => 'Kizárt kategóriák', 'export_retry' => 'Export-előkészítés újrapróbálása', 'export_revoke' => 'Ideiglenes csomag visszavonása', 'export_not_downloadable' => 'Az Owner Console nem biztosít csomagletöltést.',
        'account_access' => 'Fiókhozzáférés',
        'account_access_notice' => 'A fiók lezárása csak az ügyfélfelület hozzáférését korlátozza. Nem mondja le az előfizetést, nem indít adattörlést, nem töröl adatot és nem módosít WordPress-oldalt. A hozzáférés külön adattörlési kérelem előtt visszaállítható.',
        'active_subscription_blocks_closure' => 'Aktív előfizetés mellett a hozzáférés korlátozott lezárása nem végezhető el. Előbb zárd le az előfizetést a külön számlázási folyamatban.',
        'restrict_account_access' => 'Ügyfélfelület-hozzáférés korlátozása',
        'restore_account_access' => 'Ügyfélfelület-hozzáférés visszaállítása',
        'provider_registry' => 'Szolgáltatói és szerződéses tények', 'provider_registry_notice' => 'Csak technikai szolgáltatástérkép. A régió, szerződéses státusz és szolgáltatói törlési képesség felülvizsgálatig nincs ellenőrizve.',
        'service_scope' => 'Megfigyelt szolgáltatási kör', 'region_review' => 'Régió ténye', 'contract_review' => 'Szerződéses státusz', 'deletion_capability' => 'Törlési képesség',
    ],
];
