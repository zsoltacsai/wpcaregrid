<?php

namespace App\Operator;

use App\Backups\BackupStatus;
use Illuminate\Database\Eloquent\Builder;

/** The state of a website's most recent backup, for lists and filters. */
enum BackupState: string
{
    case Verified = 'verified';
    case InProgress = 'in_progress';
    case Failed = 'failed';
    case None = 'none';

    /** SQL for the status of the website's most recent backup (no user input). */
    public const LATEST_STATUS_SQL = '(select b.status from backups b where b.website_id = websites.id order by b.id desc limit 1)';

    public static function fromStatus(?BackupStatus $status): self
    {
        return match (true) {
            $status === BackupStatus::Verified => self::Verified,
            $status === BackupStatus::Failed, $status === BackupStatus::Corrupt => self::Failed,
            $status?->inProgress() === true => self::InProgress,
            default => self::None,
        };
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return match ($this) {
            self::Verified => [BackupStatus::Verified->value],
            self::Failed => [BackupStatus::Failed->value, BackupStatus::Corrupt->value],
            self::InProgress => array_values(array_map(fn (BackupStatus $s) => $s->value, array_filter(BackupStatus::cases(), fn (BackupStatus $s) => $s->inProgress()))),
            self::None => [BackupStatus::Expired->value, BackupStatus::Deleted->value],
        };
    }

    public function scope(Builder $websites): void
    {
        $statuses = $this->statuses();
        $in = self::LATEST_STATUS_SQL.' in ('.implode(', ', array_fill(0, count($statuses), '?')).')';

        $this === self::None
            ? $websites->where(fn (Builder $q) => $q->whereRaw(self::LATEST_STATUS_SQL.' is null')->orWhereRaw($in, $statuses))
            : $websites->whereRaw($in, $statuses);
    }

    public function label(): string
    {
        return __('operator.backup_states.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Verified => 'positive',
            self::Failed => 'danger',
            self::InProgress, self::None => 'neutral',
        };
    }
}
