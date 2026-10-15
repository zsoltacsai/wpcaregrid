<?php

namespace App\Legal;

use App\Models\LegalDocumentVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class LegalDocumentCatalog
{
    public const TYPES = ['terms_of_service', 'privacy_notice', 'cookie_notice', 'dpa'];

    public const ACCEPTANCE_MODES = [
        'disabled',
        'notice_only',
        'acceptance_required_before_checkout',
        'acceptance_required_before_account_use',
    ];

    public function current(string $type, string $locale, ?Carbon $at = null): ?LegalDocumentVersion
    {
        if (! in_array($type, self::TYPES, true) || ! in_array($locale, array_keys((array) config('localization.locales')), true)) {
            return null;
        }

        $at ??= Carbon::now('UTC');

        $document = LegalDocumentVersion::query()
            ->where('document_type', $type)
            ->where('locale', $locale)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', $at->copy()->utc())
            ->orderByDesc('effective_at')
            ->orderByDesc('version')
            ->first();

        if ($type === 'dpa' && ! in_array('all', $document?->audiences ?? [], true)) {
            return null;
        }

        return $document;
    }

    /** @return Collection<int, LegalDocumentVersion> */
    public function currentForLocale(string $locale, ?Carbon $at = null): Collection
    {
        return collect(self::TYPES)->mapWithKeys(fn (string $type) => [$type => $this->current($type, $locale, $at)])->filter();
    }

    public function historical(string $uuid): ?LegalDocumentVersion
    {
        $document = LegalDocumentVersion::query()
            ->where('uuid', $uuid)
            ->whereIn('status', ['published', 'retired'])
            ->whereNotNull('published_at')
            ->where('effective_at', '<=', Carbon::now('UTC'))
            ->first();

        if ($document?->document_type === 'dpa' && ! in_array('all', $document->audiences ?? [], true)) {
            return null;
        }

        return $document;
    }
}
