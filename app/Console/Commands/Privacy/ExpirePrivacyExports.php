<?php

namespace App\Console\Commands\Privacy;

use App\Privacy\PrivacyExportService;
use Illuminate\Console\Command;

final class ExpirePrivacyExports extends Command
{
    protected $signature = 'privacy:exports-expire {--limit=100}';

    protected $description = 'Remove only expired encrypted temporary privacy export artifacts';

    public function handle(PrivacyExportService $exports): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->components->error('The limit must be between 1 and 500.');

            return self::FAILURE;
        }

        $count = $exports->expireDue($limit);
        $this->components->info("Expired {$count} temporary privacy export artifact(s).");

        return self::SUCCESS;
    }
}
