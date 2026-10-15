<?php

namespace App\Privacy;

/** Technical provider inventory; unknown contract, region and deletion facts stay explicitly unverified. */
final class PrivacyProviderFactRegistry
{
    /** @return array<string, array{service_scope: string, data_categories: list<string>, region_status: string, contract_status: string, deletion_capability_status: string, deletion_verification_status: string, version_retention_status: string, test_mode_status: string, transfer_review_status: string, registry_source: string}> */
    public static function all(): array
    {
        return [
            PrivacyProvider::Stripe->value => self::entry('subscription billing and payment webhooks', ['BILLING', 'WEBHOOK'], 'stripe'),
            PrivacyProvider::Szamlazz->value => self::entry('invoice preparation and provider submission', ['ACCOUNTING_EVIDENCE', 'BILLING'], 'szamlazz'),
            PrivacyProvider::ObjectStorage->value => self::entry('customer-site and platform backup artifacts', ['BACKUP', 'PLATFORM_BACKUP'], 'object_storage', [
                'deletion_capability_status' => 'logical_delete_api_implemented',
                'deletion_verification_status' => 'logical_key_readback_implemented; physical_version_state_unverified',
                'version_retention_status' => 'unverified',
                'test_mode_status' => 'adapter_tests_only; provider_staging_scope_unverified',
            ]),
            PrivacyProvider::Mail->value => self::entry('transactional account and billing messages', ['ACCOUNT', 'EMAIL'], 'mail'),
            PrivacyProvider::Hosting->value => self::entry('WP CareGrid application and database hosting', ['ACCOUNT', 'AUDIT', 'BILLING', 'CUSTOMER_PROFILE', 'MONITORING', 'SECURITY', 'SESSION', 'WEBHOOK'], 'hosting'),
            PrivacyProvider::Cloudflare->value => self::entry('DNS, edge delivery and transport protection', ['WEBSITE', 'MONITORING'], 'cloudflare'),
            PrivacyProvider::Other->value => self::entry('unclassified provider; facts require review', [], 'other'),
        ];
    }

    /** @param list<string> $categories */
    private static function entry(string $scope, array $categories, string $source, array $overrides = []): array
    {
        return array_replace([
            'service_scope' => $scope,
            'data_categories' => $categories,
            'region_status' => 'unverified',
            'contract_status' => 'unverified',
            'deletion_capability_status' => 'unverified',
            'deletion_verification_status' => 'unverified',
            'version_retention_status' => 'unverified',
            'test_mode_status' => 'unverified',
            'transfer_review_status' => 'unreviewed',
            'registry_source' => $source,
        ], $overrides);
    }
}
