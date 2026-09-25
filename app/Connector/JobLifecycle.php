<?php

namespace App\Connector;

use App\AdminAccess\AdminAccessService;
use App\Backups\BackupService;
use App\Backups\RestoreService;
use App\Models\AdminAccessRequest;
use App\Models\WebsiteJob;

/**
 * Tells the backup and restore domains what happened to their connector
 * jobs, so their own state machines follow: delivered → running, and a
 * finished job settles the backup or restore it belongs to.
 */
class JobLifecycle
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly RestoreService $restores,
    ) {}

    public function delivered(WebsiteJob $job): void
    {
        if ($job->type->isBackup() && $job->backup) {
            $this->backups->started($job->backup, $job->connector);
        }

        if ($job->type === JobType::Restore && $job->restore) {
            $this->restores->started($job->restore);
        }
    }

    public function finished(WebsiteJob $job): void
    {
        if ($job->type->isBackup() && $job->backup) {
            $this->backups->jobFinished($job->backup, $job);
        }

        if ($job->type === JobType::Restore && $job->restore) {
            $this->restores->jobFinished($job->restore, $job);
        }

        if ($job->type === JobType::CreateAdminSession && ($request = AdminAccessRequest::where('job_id', $job->id)->first())) {
            app(AdminAccessService::class)->jobFinished($request, $job);
        }
    }
}
