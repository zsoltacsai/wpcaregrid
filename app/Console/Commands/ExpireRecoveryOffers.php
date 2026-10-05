<?php

namespace App\Console\Commands;

use App\Offers\RecoveryOffers;
use Illuminate\Console\Command;

/** Offers whose validity has passed expire, whether or not anyone visits the site. */
class ExpireRecoveryOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Expire recovery offers whose validity has passed (run hourly)';

    public function handle(RecoveryOffers $offers): int
    {
        $this->components->info('Expired '.$offers->expireDue().' recovery offer(s).');

        return self::SUCCESS;
    }
}
