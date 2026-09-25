<?php

namespace App\Console\Commands;

use App\AdminAccess\AdminAccessService;
use Illuminate\Console\Command;

/** Unused tokens and finished sessions expire on the server, whether or not anyone visits the site. */
class ExpireAdminAccess extends Command
{
    protected $signature = 'admin-access:expire';

    protected $description = 'Expire unused admin access tokens and ended sessions (run every minute)';

    public function handle(AdminAccessService $access): int
    {
        $this->components->info('Expired '.$access->expireDue().' admin access request(s).');

        return self::SUCCESS;
    }
}
