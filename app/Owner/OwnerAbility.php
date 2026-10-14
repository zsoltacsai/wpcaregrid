<?php

namespace App\Owner;

use App\Models\User;

/**
 * What the owner area allows. Each ability is a gate, "owner.{value}",
 * named on every owner route (a test checks that none is missing). Only
 * logins with the owner role pass; customers and operators never do,
 * whatever else they hold.
 *
 * Owners may replace fixed global service credentials, but cannot read
 * stored values, access customer/connector credentials, impersonate customers,
 * or restore, update or remediate websites.
 */
enum OwnerAbility: string
{
    case DashboardView = 'dashboard.view';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case WebsitesView = 'websites.view';
    // Non-destructive operations through the operator services: backup, security scan.
    case WebsitesOperate = 'websites.operate';
    case OperatorsManage = 'operators.manage';
    case PricingManage = 'pricing.manage';
    case ContentManage = 'content.manage';
    case SeoManage = 'seo.manage';
    // Commercial: coupons, internal plans (PROBONO) and custom recovery offers. Owner only.
    case CouponsManage = 'coupons.manage';
    case PlansAssign = 'plans.assign';
    case OffersManage = 'offers.manage';
    case SystemView = 'system.view';
    case PrivacyRequestsView = 'privacy.requests.view';
    case PlatformBackupsManage = 'platform_backups.manage';
    case IntegrationsManage = 'integrations.manage';
    case BillingInvoicesView = 'billing.invoices.view';
    case BillingSyntheticTestsManage = 'billing.synthetic_tests.manage';
    case BillingLifecycleValidationManage = 'billing.lifecycle_validation.manage';
    case SettingsManage = 'settings.manage';
    case DnsManage = 'dns.manage';

    public function gate(): string
    {
        return 'owner.'.$this->value;
    }

    public function allows(User $user): bool
    {
        return $user->isOwner() && $user->hasVerifiedEmail() && ! $user->customer()->exists();
    }
}
