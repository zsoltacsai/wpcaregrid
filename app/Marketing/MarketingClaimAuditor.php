<?php

namespace App\Marketing;

use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;

/** Finds phrases requiring human review; findings are never treated as automatic policy violations. */
final class MarketingClaimAuditor
{
    /** @var array<string, string> */
    private const PATTERNS = [
        'compliance_guarantee' => '/\b(?:GDPR[ -]compliant|fully compliant with GDPR|GDPR[- ]megfelel(?:ő|ős(?:ég)?)|teljes körű GDPR[- ]megfelel(?:ő|őség))\b/iu',
        'absolute_security' => '/\b(?:100\s*%\s*(?:secure|safe|protected)|hack[- ]proof|100\s*%[- ]os(?:an)?\s+biztonságos|feltörhetetlen)\b/iu',
        'recovery_guarantee' => '/\b(?:guaranteed (?:recovery|restore)|guaranteed to restore|garantált (?:helyreállítás|visszaállítás)|minden körülmények között visszaállít)\b/iu',
        'physical_erasure_guarantee' => '/\b(?:guaranteed physical deletion|all copies permanently erased|permanent deletion guaranteed|garantált fizikai törlés|minden másolat végleges törlése garantált)\b/iu',
        'zero_downtime' => '/\b(?:zero downtime|no downtime guaranteed|nulla leállás|leállás nélkül garantált)\b/iu',
        'complete_malware_protection' => '/\b(?:complete malware protection|protects against all malware|teljes körű védelem minden kártevő ellen)\b/iu',
        'unbounded_backup_claim' => '/\b(?:unlimited backups|unlimited backup retention|korlátlan mentés|korlátlan mentésmegőrzés)\b/iu',
        'autonomous_ai_claim' => '/\b(?:AI automatically fixes everything|AI fixes every WordPress issue|az AI automatikusan mindent megjavít|minden WordPress-hibát automatikusan kijavít)\b/iu',
        'real_time_or_24_7_claim' => '/\b(?:real[- ]time monitoring|24\s*\/\s*7 monitoring|monitored 24 hours a day|valós idejű felügyelet|0[- ]24 órás felügyelet)\b/iu',
        'universal_one_click_restore' => '/\b(?:one[- ]click restore on every host|restore any site in one click|bármely tárhelyen egy kattintásos visszaállítás|minden tárhelyen garantált visszaállítás)\b/iu',
    ];

    /** @return list<array{section: string, locale: string, source: string, rule: string}> */
    public function findings(): array
    {
        $sections = [];
        foreach (['homepage', 'site', 'pricing', 'seo'] as $group) {
            $sections += ContentSchema::group($group);
        }

        $sources = [];
        foreach ($sections as $key => $section) {
            foreach (ContentSchema::locales() as $locale) {
                $builtIn = $section->builtIn($locale);
                if ($builtIn !== null) {
                    $sources[] = [$key, $locale, 'built-in', $builtIn];
                }
            }
        }

        $published = ContentEntry::query()
            ->whereIn('key', array_keys($sections))
            ->whereNotNull('published')
            ->get(['key', 'locale', 'published']);
        foreach ($published as $entry) {
            $sources[] = [$entry->key, $entry->locale, 'published', $entry->published];
        }

        $findings = [];
        foreach ($sources as [$section, $locale, $source, $values]) {
            foreach ($this->strings($values) as $text) {
                foreach (self::PATTERNS as $rule => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $findings[] = compact('section', 'locale', 'source', 'rule');
                    }
                }
            }
        }

        return array_values(array_unique($findings, SORT_REGULAR));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            array_push($strings, ...$this->strings($item));
        }

        return $strings;
    }
}
