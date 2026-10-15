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
        'monitoring.maintenance_created',
        'privacy.request.created', 'privacy.request.status_changed', 'privacy.provider_task.created', 'privacy.provider_task.status_changed',
        'privacy.closure.impact_previewed', 'privacy.account_access.restricted', 'privacy.account_access.restored',
        'privacy.export.started', 'privacy.export.generated', 'privacy.export.failed', 'privacy.export.owner_retry',
        'privacy.export.owner_revoked', 'privacy.export.expired', 'privacy.export.cleanup_failed',
        'privacy.retention.draft_created', 'privacy.retention.preview_created', 'privacy.retention.reporting_activated',
        'privacy.retention.owner_approval_recorded', 'privacy.retention.locked_change_denied',
        'platform_backup.manual_requested', 'platform_backup.configuration_updated', 'platform_backup.disabled', 'platform_backup.escrow_attested', 'platform_backup.escrow_revoked', 'platform_backup.s3_connection_tested',
        'operator.invited', 'operator.invitation_resent', 'operator.permissions_changed', 'operator.disabled', 'operator.enabled',
        'stripe.verified',
        'integration_credential.updated', 'integration_credential.cleared',
        'billing_tax.configuration_published', 'billing_tax.seller_profile_published', 'billing_tax.review_proposed', 'billing_tax.intent_snapshot_refreshed', 'billing_tax.tax_decision_approved', 'billing_tax.local_invoice_validation', 'billing_tax.issuance_reconciliation',
        'billing_tax.synthetic_test_prepared', 'billing_tax.synthetic_test_submission_started',
        'billing_tax.synthetic_test_issued', 'billing_tax.synthetic_test_rejected',
        'billing_tax.synthetic_test_ambiguous', 'billing_tax.synthetic_test_reconciled',
        'billing_tax.synthetic_test_cancelled',
        'owner_setting.updated', 'owner_settings.mail_test_sent', 'owner_settings.mail_test_failed',
        'dns.record.create_requested', 'dns.record.update_requested', 'dns.record.delete_requested',
        'dns.record.created', 'dns.record.updated', 'dns.record.deleted', 'dns.record.failed',
        'dns.infrastructure.applied', 'dns.infrastructure.failed',
        'coupon.created', 'coupon.updated', 'coupon.activated', 'coupon.deactivated', 'coupon.deleted', 'coupon.redeemed',
        'billing.subscription_change_confirmed',
        'billing.subscription_schedule_unconfirmed',
        'billing.subscription_change_requested', 'billing.subscription_change_failed', 'billing.cancellation_scheduled', 'billing.reactivation_requested',
        'billing.subscription_activated', 'billing.payment_failure', 'billing.grace_started', 'billing.grace_expired', 'billing.payment_recovered', 'billing.payment_received',
        'billing.m11_validation_run',
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
