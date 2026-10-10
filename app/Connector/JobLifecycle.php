<?php

namespace App\Connector;

use App\AdminAccess\AdminAccessService;
use App\Backups\BackupService;
use App\Backups\PreUpdateBackups;
use App\Backups\RestoreService;
use App\Models\AdminAccessRequest;
use App\Models\SecurityRemediation;
use App\Models\SecurityScan;
use App\Models\WebsiteJob;
use App\Security\RemediationService;
use App\Security\SecurityScanService;
use App\Websites\AuditLog;

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
            $this->restores->started($job->restore, $job);
        }

        if ($job->type === JobType::SecurityScan && ($scan = SecurityScan::where('job_id', $job->id)->first())) {
            app(SecurityScanService::class)->started($scan, $job);
        }

        if (in_array($job->type, JobType::securityChanges(), true) && ($remediation = SecurityRemediation::where('job_id', $job->id)->first())) {
            app(RemediationService::class)->started($remediation);
        }

        // Once per job: a job is re-delivered when a poll response was lost.
        if ($job->type->isUpdate() && $job->attempts === 1) {
            AuditLog::record($job->website, 'update.started', data: ['job' => $job->uuid, 'type' => $job->type->value, 'target' => JobQueue::updateTarget($job->payload ?? [])]);
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

        // Blocked updates (no verified pre-update backup) are recorded as update.blocked instead.
        if ($job->type->isUpdate() && $job->result_code !== PreUpdateBackups::BLOCKED) {
            AuditLog::record($job->website, in_array($job->status, [JobStatus::Success, JobStatus::Skipped], true) ? 'update.completed' : 'update.failed', data: [
                'job' => $job->uuid,
                'type' => $job->type->value,
                'target' => JobQueue::updateTarget($job->payload ?? []),
                'status' => $job->status->value,
                // Not "code": that key holds backup error codes in the customer's backup activity.
                'result' => $job->result_code ?? ($job->error['code'] ?? null),
            ]);
        }

        if ($job->type === JobType::SecurityScan && ($scan = SecurityScan::where('job_id', $job->id)->first())) {
            app(SecurityScanService::class)->jobFinished($scan, $job);
        }

        if (in_array($job->type, JobType::securityChanges(), true) && ($remediation = SecurityRemediation::where('job_id', $job->id)->first())) {
            app(RemediationService::class)->jobFinished($remediation, $job);
        }

        if ($job->type === JobType::CreateAdminSession && ($request = AdminAccessRequest::where('job_id', $job->id)->first())) {
            app(AdminAccessService::class)->jobFinished($request, $job);
        }
    }
}
