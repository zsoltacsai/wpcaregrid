<?php

namespace App\Console\Commands;

use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Operator tool: queue a whitelisted job for a website, e.g.
 *   php artisan connector:queue-job <website-uuid> UPDATE_PLUGIN --payload='{"plugin":"akismet/akismet.php"}'
 */
class QueueConnectorJob extends Command
{
    protected $signature = 'connector:queue-job {website : Website UUID} {type : Job type} {--payload={} : JSON payload}';

    protected $description = 'Queue a connector job for a website';

    public function handle(JobQueue $queue): int
    {
        $website = Website::where('uuid', $this->argument('website'))->first();
        $type = JobType::tryFrom(strtoupper($this->argument('type')));
        $payload = json_decode((string) $this->option('payload'), true);

        if (! $website || ! $type || ! is_array($payload)) {
            $this->error('Unknown website, unsupported job type or invalid JSON payload. Supported: '.implode(', ', array_column(JobType::cases(), 'value')));

            return self::FAILURE;
        }

        try {
            $job = $queue->queue($website->load('activeConnector'), $type, $payload, 'operator');
        } catch (ConnectorError|ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued {$type->value} job {$job->uuid}");

        return self::SUCCESS;
    }
}
