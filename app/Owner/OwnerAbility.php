<?php

namespace App\Owner;

use App\Models\User;

/**
 * What the owner area allows. Each ability is a gate, "owner.{value}",
 * named on every owner route (a test checks that none is missing). Only
 * logins with the owner role pass; customers and operators never do,
 * whatever else they hold.
 *
 * There is deliberately no ability to read secrets (Stripe keys, storage
 * or connector credentials, passwords), to impersonate customers, or to
 * restore, update or remediate websites: those stay with operators and
 * their explicit permissions.
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
    case SystemView = 'system.view';

    public function gate(): string
    {
        return 'owner.'.$this->value;
    }

    public function allows(User $user): bool
    {
        return $user->isOwner() && $user->hasVerifiedEmail() && ! $user->customer()->exists();
    }
}
