<?php

namespace App\SyntheticFixtures;

use App\Models\Customer;
use App\Models\SyntheticSiteFixture;
use App\Models\Website;
use App\Owner\AdminAudit;
use App\Pricing\ProductType;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SyntheticFixtureProvisioner
{
    public function __construct(private readonly StagingIdentity $identity) {}

    /** @return array{customer: Customer, website: Website} */
    public function provision(string $customerName, string $email, string $host): array
    {
        if (! $this->identity->allowsCurrentDeployment() || ! config('synthetic_fixtures.provisioning_enabled')) {
            throw ValidationException::withMessages(['fixture' => 'Synthetic fixture provisioning is disabled outside the explicitly identified staging deployment.']);
        }

        $expectedName = (string) config('synthetic_fixtures.customer_name');
        $expectedEmail = (string) config('synthetic_fixtures.customer_email');
        $expectedHost = (string) config('synthetic_fixtures.site_host');
        $parsed = parse_url($host);
        $actualHost = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
        $websiteUrl = 'https://'.$expectedHost;
        $hostIsAllowed = is_array($parsed)
            && strtolower((string) ($parsed['scheme'] ?? '')) === 'https'
            && $actualHost === $expectedHost
            && in_array($parsed['path'] ?? '', ['', '/'], true)
            && ! isset($parsed['user'])
            && ! isset($parsed['pass'])
            && ! isset($parsed['port'])
            && ! isset($parsed['query'])
            && ! isset($parsed['fragment']);

        if ($customerName !== $expectedName || Customer::normalizeEmail($email) !== $expectedEmail
            || ! $hostIsAllowed) {
            throw ValidationException::withMessages(['fixture' => 'Only the predeclared M8 synthetic customer and HTTPS test hostname are accepted.']);
        }

        return $this->createFixture(
            purpose: (string) config('synthetic_fixtures.purpose'),
            customerName: $customerName,
            email: $email,
            websiteUrl: $websiteUrl,
            backupRestoreEntitled: true,
            ttlDays: (int) config('synthetic_fixtures.ttl_days', 30),
            label: 'M8',
        );
    }

    /** @return array{customer: Customer, website: Website} */
    public function provisionM14SecurityFixture(): array
    {
        if (! $this->identity->allowsCurrentDeployment() || ! config('m14_security_fixture.provisioning_enabled')) {
            throw ValidationException::withMessages(['fixture' => 'M14 security fixture provisioning is disabled outside the explicitly identified staging deployment.']);
        }

        $customerName = (string) config('m14_security_fixture.customer_name');
        $email = (string) config('m14_security_fixture.customer_email');
        $expectedHost = (string) config('m14_security_fixture.site_host');
        $host = 'https://'.$expectedHost;
        $parsed = parse_url($host);

        if (! preg_match('/^m14-security-[a-z0-9](?:[a-z0-9-]{0,46}[a-z0-9])?\.staging\.wpcaregrid\.com$/D', $expectedHost)
            || ! is_array($parsed)
            || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parsed['host'] ?? '')) !== $expectedHost
            || ! in_array($parsed['path'] ?? '', ['', '/'], true)
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['port'])
            || isset($parsed['query']) || isset($parsed['fragment'])) {
            throw ValidationException::withMessages(['fixture' => 'The reserved M14 security fixture identity is invalid.']);
        }

        return $this->createFixture(
            purpose: (string) config('m14_security_fixture.purpose'),
            customerName: $customerName,
            email: $email,
            websiteUrl: $host,
            backupRestoreEntitled: false,
            ttlDays: max(1, min(7, (int) config('m14_security_fixture.ttl_days', 7))),
            label: 'M14 security',
        );
    }

    /** @return array{customer: Customer, website: Website} */
    private function createFixture(string $purpose, string $customerName, string $email, string $websiteUrl, bool $backupRestoreEntitled, int $ttlDays, string $label): array
    {
        try {
            return DB::transaction(function () use ($purpose, $customerName, $email, $websiteUrl, $backupRestoreEntitled, $ttlDays, $label) {
                if (SyntheticSiteFixture::query()->where('purpose', $purpose)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['fixture' => "The {$label} synthetic fixture already exists; duplicate provisioning is refused."]);
                }

                [$firstName, $lastName] = array_pad(explode(' ', $customerName, 2), 2, 'E2E');
                $customer = Customer::query()->create([
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'company_name' => $customerName,
                    'country_code' => 'HU',
                    'customer_type' => 'company',
                    'active' => true,
                    // Deliberately no user, tax identity, Stripe customer, subscription, or billing record.
                ]);

                $website = Website::query()->create([
                    'customer_id' => $customer->id,
                    'url' => $websiteUrl,
                    'type' => ProductType::WordPress,
                    'status' => WebsiteStatus::Pending,
                    'connector_status' => ConnectorStatus::NotInstalled,
                ]);

                SyntheticSiteFixture::query()->create([
                    'purpose' => $purpose,
                    'environment' => 'staging',
                    'customer_id' => $customer->id,
                    'website_id' => $website->id,
                    'enabled' => true,
                    'backup_restore_entitled' => $backupRestoreEntitled,
                    'expires_at' => now()->addDays(max(1, min(90, $ttlDays))),
                ]);

                AdminAudit::record(null, 'synthetic_fixture.provisioned', 'synthetic_site_fixture', (string) $website->uuid, [
                    'purpose' => $purpose,
                    'customer' => $customer->uuid,
                    'website' => $website->uuid,
                    'entitlement' => $backupRestoreEntitled ? 'synthetic_backup_restore' : 'none',
                ]);

                return compact('customer', 'website');
            });
        } catch (QueryException $exception) {
            if (SyntheticSiteFixture::query()->where('purpose', $purpose)->exists()
                || Customer::query()->where('email', Customer::normalizeEmail($email))->exists()
                || Website::query()->where('url', $websiteUrl)->exists()) {
                throw ValidationException::withMessages(['fixture' => "The {$label} synthetic fixture or one of its reserved identities already exists."]);
            }

            throw $exception;
        }
    }
}
