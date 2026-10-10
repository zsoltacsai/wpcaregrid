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

        try {
            return DB::transaction(function () use ($customerName, $email, $websiteUrl) {
                if (SyntheticSiteFixture::query()->where('purpose', config('synthetic_fixtures.purpose'))->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['fixture' => 'The M8 synthetic fixture already exists; duplicate provisioning is refused.']);
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
                    // Deliberately no user, tax identity, Stripe customer, or subscription.
                ]);

                $website = Website::query()->create([
                    'customer_id' => $customer->id,
                    'url' => $websiteUrl,
                    'type' => ProductType::WordPress,
                    'status' => WebsiteStatus::Pending,
                    'connector_status' => ConnectorStatus::NotInstalled,
                ]);

                SyntheticSiteFixture::query()->create([
                    'purpose' => config('synthetic_fixtures.purpose'),
                    'environment' => 'staging',
                    'customer_id' => $customer->id,
                    'website_id' => $website->id,
                    'enabled' => true,
                    'backup_restore_entitled' => true,
                    'expires_at' => now()->addDays(max(1, min(90, (int) config('synthetic_fixtures.ttl_days', 30)))),
                ]);

                AdminAudit::record(null, 'synthetic_fixture.provisioned', 'synthetic_site_fixture', (string) $website->uuid, [
                    'purpose' => config('synthetic_fixtures.purpose'),
                    'customer' => $customer->uuid,
                    'website' => $website->uuid,
                    'entitlement' => 'synthetic_backup_restore',
                ]);

                return compact('customer', 'website');
            });
        } catch (QueryException $exception) {
            if (SyntheticSiteFixture::query()->where('purpose', config('synthetic_fixtures.purpose'))->exists()
                || Customer::query()->where('email', Customer::normalizeEmail($email))->exists()
                || Website::query()->where('url', $websiteUrl)->exists()) {
                throw ValidationException::withMessages(['fixture' => 'The M8 synthetic fixture or one of its reserved identities already exists.']);
            }

            throw $exception;
        }
    }
}
