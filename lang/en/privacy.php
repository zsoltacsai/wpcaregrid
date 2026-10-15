<?php

return [
    'types' => ['access' => 'Access / export request', 'rectification' => 'Rectification request', 'account_closure' => 'Account closure request', 'erasure' => 'Erasure request'],
    'request_statuses' => [
        'requested' => 'Requested', 'identity_verification_required' => 'Identity verification required', 'verified' => 'Identity verified',
        'cooling_off' => 'Cooling-off period', 'retention_analysis' => 'Retention analysis (report only)', 'review_pending' => 'Review pending', 'blocked_policy' => 'Blocked by policy review', 'blocked_legal' => 'Blocked pending legal review', 'blocked_accounting' => 'Blocked pending accounting review', 'access_restricted' => 'Customer access restricted', 'manual_review' => 'Manual review', 'failed' => 'Failed', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ],
    'provider_task_statuses' => ['pending' => 'Pending', 'requested' => 'Requested', 'confirmed' => 'Confirmed', 'retained_legal' => 'Retained pending review', 'failed' => 'Failed', 'manual_review' => 'Manual review'],
    'gate_states' => ['SAFE_DISABLED_BY_POLICY' => 'SAFE / DISABLED BY POLICY', 'REQUIRES_REVIEW' => 'Enabled — requires review'],
    'fact_statuses' => ['unverified' => 'Unverified', 'unreviewed' => 'Not reviewed'],
    'providers' => ['stripe' => 'Stripe', 'szamlazz' => 'Számlázz.hu', 'object_storage' => 'Object storage', 'mail' => 'Mail provider', 'hosting' => 'Hosting', 'cloudflare' => 'Cloudflare', 'other' => 'Other'],
    'events' => ['created' => 'Case created', 'identity_verified' => 'Identity verification recorded', 'status_changed' => 'Status changed', 'account_access_restricted' => 'Customer-area access restricted', 'account_access_restored' => 'Customer-area access restored', 'provider_task_created' => 'Provider reconciliation task recorded', 'provider_task_status_changed' => 'Provider task status changed', 'export_requested' => 'Customer export requested', 'export_queued' => 'Export preparation queued', 'export_generating' => 'Export preparation started', 'export_generated' => 'Encrypted export generated', 'export_failed' => 'Export preparation failed', 'export_cleanup_failed' => 'Temporary export cleanup needs review', 'export_downloaded' => 'Export downloaded', 'export_link_renewed' => 'Download link renewed', 'export_revoked' => 'Export package revoked', 'export_expired' => 'Temporary export expired'],
    'owner' => [
        'title' => 'Privacy requests', 'lead' => 'Read-only operational view of non-destructive privacy case metadata.',
        'non_destructive_notice' => 'Owner case management and encrypted customer export status are available here. Erasure, provider deletion, and source-data retention execution remain unavailable.',
        'request_id' => 'Request ID', 'customer_reference' => 'Customer reference', 'type' => 'Request type', 'status' => 'State', 'requested_at' => 'Requested (UTC)',
        'reviewed_at' => 'Reviewed (UTC)', 'provider_tasks' => 'Provider tasks', 'provider' => 'Provider', 'scope' => 'Data category', 'attempts' => 'Attempts',
        'error_category' => 'Safe error category', 'history' => 'Case history', 'empty' => 'No privacy cases recorded.', 'request_detail' => 'Privacy request detail',
        'detail_lead' => 'Identifiers and operational state only. Customer content and request narratives are not shown.', 'retention_gate' => 'Destructive retention gate',
        'no_provider_tasks' => 'No provider reconciliation tasks recorded.',
        'export' => 'Customer export package', 'export_status' => 'Package state', 'export_coverage' => 'Coverage', 'export_partial' => 'Partial — excluded categories remain under review', 'export_size' => 'Encrypted package size', 'export_generated_at' => 'Generated (UTC)', 'export_expires_at' => 'Package expiry (UTC)', 'export_link_expires_at' => 'Customer link expiry (UTC)', 'export_download_count' => 'Authenticated downloads', 'export_failure_code' => 'Safe failure code', 'export_included' => 'Included sections', 'export_excluded' => 'Excluded categories', 'export_retry' => 'Retry export preparation', 'export_revoke' => 'Revoke temporary package', 'export_not_downloadable' => 'Owner Console does not provide package downloads.',
        'account_access' => 'Account access',
        'account_access_notice' => 'Account closure restricts customer-area access only. It does not cancel billing, start erasure, delete data, or modify WordPress sites. Access can be restored before a separate erasure request is active.',
        'active_subscription_blocks_closure' => 'Access restriction is blocked while a subscription is still active. End it through the separate billing workflow first.',
        'restrict_account_access' => 'Restrict customer-area access',
        'restore_account_access' => 'Restore customer-area access',
        'provider_registry' => 'Provider and contract facts', 'provider_registry_notice' => 'Technical service mapping only. Regions, contract status and provider deletion capabilities remain unverified until reviewed.',
        'service_scope' => 'Observed service scope', 'region_review' => 'Region fact', 'contract_review' => 'Contract status', 'deletion_capability' => 'Deletion capability',
    ],
];
