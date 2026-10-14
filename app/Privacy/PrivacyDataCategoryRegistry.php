<?php

namespace App\Privacy;

/** Engineering handling metadata only; purpose codes are not lawful-basis conclusions. */
final class PrivacyDataCategoryRegistry
{
    /**
     * @return array<string, array{sensitivity: string, purpose_codes: list<string>, operational_owner: string, source_systems: list<string>, retention_class: string, deletion_behavior: string, export_candidate: bool, erasure_candidate: bool, immutable_evidence_candidate: bool, approval_required: bool, providers: list<string>}>
     */
    public static function all(): array
    {
        return [
            'ACCOUNT' => self::entry('personal', ['account_access'], 'platform', ['customer_portal'], 'pending_review', 'case_by_case_review', true, true, false, true, ['hosting', 'mail']),
            'CUSTOMER_PROFILE' => self::entry('personal', ['account_profile'], 'platform', ['customer_portal'], 'pending_review', 'case_by_case_review', true, true, false, true, ['hosting']),
            'BILLING' => self::entry('financial', ['subscription_billing'], 'platform', ['stripe', 'customer_portal'], 'pending_accounting_review', 'financial_exception_review', true, true, true, true, ['stripe', 'hosting']),
            'ACCOUNTING_EVIDENCE' => self::entry('restricted_financial', ['invoice_preparation', 'accounting_evidence'], 'platform', ['szamlazz', 'billing'], 'pending_accounting_review', 'immutable_evidence_review', false, false, true, true, ['szamlazz', 'hosting']),
            'WEBSITE' => self::entry('customer_data', ['website_operations'], 'customer_scope', ['wordpress_connector', 'owner_console'], 'pending_review', 'case_by_case_review', true, true, false, true, ['hosting', 'object_storage']),
            'BACKUP' => self::entry('restricted_customer_data', ['website_recovery'], 'customer_scope', ['backup_engine'], 'pending_review', 'backup_expiry_review', false, true, false, true, ['object_storage']),
            'SECURITY' => self::entry('security_sensitive', ['security_operations'], 'platform', ['security_scanner', 'owner_console'], 'pending_security_review', 'security_evidence_review', false, true, true, true, ['hosting']),
            'MONITORING' => self::entry('customer_data', ['service_health_monitoring'], 'platform', ['connector', 'monitoring_engine'], 'pending_review', 'case_by_case_review', true, true, false, true, ['hosting', 'mail']),
            'AUDIT' => self::entry('restricted_personal', ['accountability_audit'], 'platform', ['owner_console', 'operator_console'], 'pending_security_review', 'immutable_evidence_review', false, true, true, true, ['hosting']),
            'WEBHOOK' => self::entry('restricted_financial', ['provider_event_reconciliation'], 'platform', ['stripe_webhooks'], 'pending_review', 'payload_minimization_review', false, true, false, true, ['stripe', 'hosting']),
            'EMAIL' => self::entry('personal', ['transactional_communication'], 'platform', ['mail_outbox'], 'pending_review', 'payload_minimization_review', false, true, false, true, ['mail', 'hosting']),
            'SESSION' => self::entry('security_sensitive', ['authentication_session'], 'platform', ['web_session'], 'pending_security_review', 'session_expiry_review', false, true, false, true, ['hosting']),
            'PLATFORM_BACKUP' => self::entry('restricted_platform_data', ['platform_disaster_recovery'], 'platform', ['platform_backup'], 'pending_review', 'backup_expiry_review', false, true, false, true, ['object_storage', 'hosting']),
        ];
    }

    public static function has(string $category): bool
    {
        return array_key_exists($category, self::all());
    }

    /** @param list<string> $purposeCodes @param list<string> $sourceSystems @param list<string> $providers */
    private static function entry(string $sensitivity, array $purposeCodes, string $operationalOwner, array $sourceSystems, string $retentionClass, string $deletionBehavior, bool $export, bool $erase, bool $immutable, bool $approval, array $providers): array
    {
        return [
            'sensitivity' => $sensitivity,
            'purpose_codes' => $purposeCodes,
            'operational_owner' => $operationalOwner,
            'source_systems' => $sourceSystems,
            'retention_class' => $retentionClass,
            'deletion_behavior' => $deletionBehavior,
            'export_candidate' => $export,
            'erasure_candidate' => $erase,
            'immutable_evidence_candidate' => $immutable,
            'approval_required' => $approval,
            'providers' => $providers,
        ];
    }
}
