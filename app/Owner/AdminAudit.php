<?php

namespace App\Owner;

use App\Models\AdminAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Records what owners change: content, prices, customers and operators.
 * Entries hold identifiers, field names and codes only, never passwords,
 * tokens, secrets or card data. Website operations are recorded in the
 * per-website audit log (App\Websites\AuditLog) by the services as usual.
 */
final class AdminAudit
{
    public const EVENTS = [
        'owner.signed_in',
        'content.created', 'content.updated', 'content.published', 'content.unpublished', 'content.draft_discarded',
        'price.created', 'price.updated', 'price.published', 'price.draft_discarded',
        'customer.updated', 'customer.vat_rechecked', 'customer.vat_review_resolved',
        'synthetic_fixture.provisioned',
        'operator.invited', 'operator.invitation_resent', 'operator.permissions_changed', 'operator.disabled', 'operator.enabled',
        'stripe.verified',
        'integration_credential.updated', 'integration_credential.cleared',
        'billing_tax.configuration_published', 'billing_tax.review_proposed', 'billing_tax.issuance_reconciliation',
        'billing_tax.synthetic_test_prepared', 'billing_tax.synthetic_test_submission_started',
        'billing_tax.synthetic_test_issued', 'billing_tax.synthetic_test_rejected',
        'billing_tax.synthetic_test_ambiguous', 'billing_tax.synthetic_test_reconciled',
        'billing_tax.synthetic_test_cancelled',
        'owner_setting.updated', 'owner_settings.mail_test_sent', 'owner_settings.mail_test_failed',
        'dns.record.create_requested', 'dns.record.update_requested', 'dns.record.delete_requested',
        'dns.record.created', 'dns.record.updated', 'dns.record.deleted', 'dns.record.failed',
        'dns.infrastructure.applied', 'dns.infrastructure.failed',
        'coupon.created', 'coupon.updated', 'coupon.activated', 'coupon.deactivated', 'coupon.deleted', 'coupon.redeemed',
        'probono.assigned', 'probono.changed', 'probono.ended', 'probono.started', 'probono.expired',
        'offer.created', 'offer.updated', 'offer.sent', 'offer.accepted', 'offer.declined', 'offer.paid', 'offer.cancelled', 'offer.expired', 'offer.completed',
    ];

    /** @param  array<string, scalar|list<scalar>|null>  $data */
    public static function record(?User $actor, string $event, string $subjectType, string $subjectKey, array $data = []): AdminAuditEvent
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown admin audit event {$event}.");
        }

        Log::info("Owner event {$event}.", ['owner' => $actor?->id, 'subject' => $subjectType.':'.$subjectKey] + $data);

        return AdminAuditEvent::create([
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor?->isOwner() ? 'owner' : 'system',
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_key' => mb_substr($subjectKey, 0, 128),
            'data' => $data ?: null,
            'ip_address' => request()->route() ? request()->ip() : null,
        ]);
    }
}
