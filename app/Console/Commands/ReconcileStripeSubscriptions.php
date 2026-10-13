<?php

namespace App\Console\Commands;

use App\Billing\ReconcileStripeSubscriptions as Reconciler;
use Illuminate\Console\Command;

class ReconcileStripeSubscriptions extends Command
{
    protected $signature = 'stripe:subscriptions:reconcile {--limit=100}';

    protected $description = 'Reconcile local Stripe subscription mirrors against provider state';

    public function handle(Reconciler $reconciler): int
    {
        $result = $reconciler->run((int) $this->option('limit'));
        $this->line('Checked: '.$result['checked'].', reconciled: '.$result['reconciled'].', changed: '.$result['changed'].', skipped: '.$result['skipped'].', failed: '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
