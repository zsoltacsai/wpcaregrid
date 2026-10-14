<?php

namespace App\Privacy;

/** Technical provider inventory; unknown contract, region and deletion facts stay explicitly unverified. */
final class PrivacyProviderFactRegistry
{
    /** @return array<string, array{service_scope: string, data_categories: list<string>, region_status: string, contract_status: string, deletion_capability_status: string, transfer_review_status: string, registry_source: string}> */
    public static function all(): array
    {
        return [
            PrivacyProvider::Stripe->value => self::entry('subscription billing and payment webhooks', ['BILLING', 'WEBHOOK'], 'stripe'),
            PrivacyProvider::Szamlazz->value => self::entry('invoice preparation and provider submission', ['ACCOUNTING_EVIDENCE', 'BILLING'], 'szamlazz'),
            PrivacyProvider::ObjectStorage->value => self::entry('customer-site and platform backup artifacts', ['BACKUP', 'PLATFORM_BACKUP'], 'object_storage'),
            PrivacyProvider::Mail->value => self::entry('transactional account and billing messages', ['ACCOUNT', 'EMAIL'], 'mail'),
            PrivacyProvider::Hosting->value => self::entry('WP CareGrid application and database hosting', ['ACCOUNT', 'AUDIT', 'BILLING', 'CUSTOMER_PROFILE', 'MONITORING', 'SECURITY', 'SESSION', 'WEBHOOK'], 'hosting'),
            PrivacyProvider::Cloudflare->value => self::entry('DNS, edge delivery and transport protection', ['WEBSITE', 'MONITORING'], 'cloudflare'),
            PrivacyProvider::Other->value => self::entry('unclassified provider; facts require review', [], 'other'),
        ];
    }

    /** @param list<string> $categories */
    private static function entry(string $scope, array $categories, string $source): array
    {
        return [
            'service_scope' => $scope,
            'data_categories' => $categories,
            'region_status' => 'unverified',
            'contract_status' => 'unverified',
            'deletion_capability_status' => 'unverified',
            'transfer_review_status' => 'unreviewed',
            'registry_source' => $source,
        ];
    }
}
