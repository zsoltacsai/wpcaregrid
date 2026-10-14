<?php

namespace App\Privacy;

/** Owner-approved technical defaults. These are not statutory/legal/accounting approvals. */
final class RetentionPolicyDefaults
{
    /** @return array<string, array{category:string,trigger:string,type:string,value:mixed,unit?:string,legal:bool,accounting:bool,security:bool,compliance_lock:bool,configurable:bool}> */
    public static function all(): array
    {
        $duration = static fn (string $category, string $trigger, int $value, string $unit = 'days', bool $legal = true, bool $accounting = false, bool $security = false, bool $locked = false, bool $configurable = true): array => [
            'category' => $category, 'trigger' => $trigger, 'type' => 'duration', 'value' => ['value' => $value, 'unit' => $unit],
            'unit' => $unit, 'legal' => $legal, 'accounting' => $accounting, 'security' => $security,
            'compliance_lock' => $locked, 'configurable' => $configurable,
        ];
        $flag = static fn (string $category, string $trigger, bool $value, bool $legal = false, bool $accounting = false, bool $security = false, bool $locked = true): array => [
            'category' => $category, 'trigger' => $trigger, 'type' => 'boolean', 'value' => $value,
            'legal' => $legal, 'accounting' => $accounting, 'security' => $security,
            'compliance_lock' => $locked, 'configurable' => false,
        ];

        return [
            'RET-01' => $duration('CUSTOMER_PROFILE', 'account_closed_at', 14, legal: false),
            'RET-02' => ['category' => 'CUSTOMER_PROFILE', 'trigger' => 'erasure_completed_at', 'type' => 'strategy', 'value' => 'anonymize', 'legal' => true, 'accounting' => true, 'security' => true, 'compliance_lock' => true, 'configurable' => false],
            'RET-03' => ['category' => 'CUSTOMER_PROFILE', 'trigger' => 'anonymization', 'type' => 'strategy', 'value' => 'deleted+opaque-id@privacy.invalid', 'legal' => true, 'accounting' => false, 'security' => true, 'compliance_lock' => true, 'configurable' => false],
            'RET-04' => $duration('BACKUP', 'service_ended_at', 30, legal: true, security: true),
            'RET-05' => $duration('EXPORT', 'export_link_created_at', 24, 'hours', legal: true, security: true),
            'RET-06' => $duration('EXPORT', 'export_created_at', 7, legal: true, security: true),
            'RET-07' => $duration('WEBHOOK', 'received_at', 30, legal: true, accounting: true, security: true),
            'RET-08' => $duration('WEBHOOK_IDEMPOTENCY', 'received_at', 365, legal: true, accounting: true, security: true),
            'RET-09' => $duration('EMAIL_CONTENT', 'created_at', 30, legal: true, accounting: true, security: true),
            'RET-10' => $duration('EMAIL_METADATA', 'created_at', 180, legal: true, accounting: true, security: true),
            'RET-11' => $duration('APPLICATION_LOG', 'created_at', 30, legal: true, security: true),
            'RET-12' => $duration('SECURITY_AUDIT', 'created_at', 365, 'days', true, true, true, true, false),
            'RET-13' => $duration('STAGING_SYNTHETIC', 'created_at', 30, legal: true, security: true),
            'RET-14' => $duration('PROVIDER_DELETE_TASK', 'requested_at', 30, legal: true, accounting: true, security: true),
            'RET-15' => $duration('PRIVACY_CASE', 'completed_at', 3, 'years', true, true, true, true, false),
            'RET-16' => $duration('PRIVACY_REQUEST', 'requested_at', 14, legal: true, security: true),
            'RET-17' => $flag('PRIVACY_TOMBSTONE', 'restore_replay', true, true, true, true),
            'RET-18' => $flag('GLOBAL_SAFETY', 'wave4', false),
            'RET-19' => $flag('GLOBAL_SAFETY', 'before_execution', true),
            'RET-20' => $flag('GLOBAL_SAFETY', 'policy_change', true, true, true),
            'RET-21' => ['category' => 'GLOBAL_SAFETY', 'trigger' => 'future_execution', 'type' => 'integer', 'value' => 25, 'legal' => false, 'accounting' => false, 'security' => true, 'compliance_lock' => true, 'configurable' => false],
            'RET-22' => $flag('BACKUP_SAFETY', 'active_restore', true, false, false, true),
            'RET-23' => $flag('BACKUP_SAFETY', 'active_operation', true, false, false, true),
            'RET-24' => $flag('BACKUP_SAFETY', 'last_verified_recovery_point', true, false, false, true),
            'RET-25' => $flag('PROVIDER_SAFETY', 'provider_delete', true, true, true, true),
            'RET-26' => ['category' => 'ACCOUNTING_EVIDENCE', 'trigger' => 'accounting_record_created_at', 'type' => 'compliance', 'value' => null, 'legal' => true, 'accounting' => true, 'security' => false, 'compliance_lock' => true, 'configurable' => false],
            'RET-27' => $flag('ACCOUNTING_EVIDENCE', 'profile_anonymization', true, true, true),
            'RET-28' => $flag('GLOBAL_POLICY', 'policy_effective_at', true, true, true, false),
            'RET-29' => $flag('GLOBAL_POLICY', 'policy_version', true),
            'RET-30' => $flag('GLOBAL_POLICY', 'policy_activation', true, false, false, true),
            'RET-31' => $flag('GLOBAL_POLICY', 'category_specific', true, true, true, false),
            'RET-32' => $flag('GLOBAL_POLICY', 'tenant_override', false, true, true, false),
            'RET-33' => $flag('BACKUP', 'plan_vs_post_service', true, true, false, true),
        ];
    }
}
