<?php

namespace App\Models;

use App\Privacy\PrivacyExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyExportArtifact extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => PrivacyExportStatus::class,
            'included_sections' => 'array',
            'excluded_sections' => 'array',
            'package_size' => 'integer',
            'attempts' => 'integer',
            'download_count' => 'integer',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'download_expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }

    /** The only supported object path is derived from this opaque artifact UUID. */
    public function expectedStoragePath(): string
    {
        return 'privacy-exports/'.$this->uuid.'.enc';
    }
}
