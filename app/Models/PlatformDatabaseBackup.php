<?php

namespace App\Models;

use App\Platform\DisasterRecovery\PlatformRecoveryHealth;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformDatabaseBackup extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PlatformBackupDelivery::class);
    }

    public function recoveryHealth(): PlatformRecoveryHealth
    {
        return match ($this->status) {
            'verified' => match (true) {
                ! $this->verified_at => PlatformRecoveryHealth::Degraded,
                $this->verified_at->lt(now()->subHours(24)) => PlatformRecoveryHealth::Stale,
                default => PlatformRecoveryHealth::Healthy,
            },
            'verification_failed' => PlatformRecoveryHealth::VerificationFailed,
            'failed' => PlatformRecoveryHealth::Degraded,
            default => PlatformRecoveryHealth::Degraded,
        };
    }

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'size_bytes' => 'integer',
            'created_for_backup_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
