<?php

namespace App\Content;

use App\Content\Schema\ContentSchema;
use App\Content\Schema\Section;
use App\Models\ContentEntry;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Draft and publish for content sections. Drafts never reach public pages;
 * publishing validates every required field, stores the values and clears
 * the published-content cache so the next request shows them. Every change
 * is audited with the owner, the section and the locale.
 */
final class ContentEditor
{
    public function __construct(private readonly ContentRepository $content) {}

    public function entry(Section $section, string $locale): ?ContentEntry
    {
        return ContentEntry::where('key', $section->key)->where('locale', $locale)->first();
    }

    /** What the editor form starts from: the draft, else published, else built-in copy in this locale, else empty. */
    public function editable(Section $section, string $locale, ?ContentEntry $entry): array
    {
        return $section->complete($entry?->draft ?? $entry?->published ?? $section->builtIn($locale) ?? $section->emptyValues());
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function saveDraft(Section $section, string $locale, array $input, User $owner): ContentEntry
    {
        $this->guard($locale);
        $values = ContentValidator::validate($section, $input, publishing: false);

        return DB::transaction(function () use ($section, $locale, $values, $owner) {
            $entry = ContentEntry::firstOrNew(['key' => $section->key, 'locale' => $locale]);
            $created = ! $entry->exists;

            $entry->forceFill(['draft' => $values, 'draft_updated_at' => now(), 'draft_updated_by' => $owner->id])->save();
            AdminAudit::record($owner, $created ? 'content.created' : 'content.updated', 'content', $this->subject($section, $locale), ['section' => $section->key, 'locale' => $locale]);

            return $entry;
        });
    }

    /**
     * Validate and publish these values (the editor submits the form it shows).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function publish(Section $section, string $locale, array $input, User $owner): ContentEntry
    {
        $this->guard($locale);
        $values = ContentValidator::validate($section, $input, publishing: true);

        $entry = DB::transaction(function () use ($section, $locale, $values, $owner) {
            $entry = ContentEntry::firstOrNew(['key' => $section->key, 'locale' => $locale]);
            if (! $entry->exists) {
                $entry->save();
                AdminAudit::record($owner, 'content.created', 'content', $this->subject($section, $locale), ['section' => $section->key, 'locale' => $locale]);
            }

            $entry->forceFill([
                'published' => $values,
                'published_at' => now(),
                'published_by' => $owner->id,
                'draft' => null,
                'draft_updated_at' => null,
                'draft_updated_by' => null,
            ])->save();
            AdminAudit::record($owner, 'content.published', 'content', $this->subject($section, $locale), ['section' => $section->key, 'locale' => $locale]);

            return $entry;
        });

        $this->content->flush();

        return $entry;
    }

    /** Take a section off the public page; its values are kept as the draft. */
    public function unpublish(Section $section, string $locale, User $owner): ?ContentEntry
    {
        $this->guard($locale);
        $entry = $this->entry($section, $locale);
        if ($entry?->published === null) {
            return $entry;
        }

        DB::transaction(function () use ($entry, $section, $locale, $owner) {
            $entry->forceFill([
                'draft' => $entry->draft ?? $entry->published,
                'draft_updated_at' => $entry->draft_updated_at ?? now(),
                'draft_updated_by' => $entry->draft_updated_by ?? $owner->id,
                'published' => null,
                'published_at' => null,
                'published_by' => null,
            ])->save();
            AdminAudit::record($owner, 'content.unpublished', 'content', $this->subject($section, $locale), ['section' => $section->key, 'locale' => $locale]);
        });

        $this->content->flush();

        return $entry;
    }

    public function discardDraft(Section $section, string $locale, User $owner): void
    {
        $this->guard($locale);
        $entry = $this->entry($section, $locale);
        if ($entry?->draft === null) {
            return;
        }

        DB::transaction(function () use ($entry, $section, $locale, $owner) {
            $entry->published === null
                ? $entry->delete()
                : $entry->forceFill(['draft' => null, 'draft_updated_at' => null, 'draft_updated_by' => null])->save();
            AdminAudit::record($owner, 'content.draft_discarded', 'content', $this->subject($section, $locale), ['section' => $section->key, 'locale' => $locale]);
        });
    }

    private function guard(string $locale): void
    {
        if (! in_array($locale, ContentSchema::locales(), true)) {
            throw new InvalidArgumentException("Unsupported content locale [{$locale}].");
        }
    }

    private function subject(Section $section, string $locale): string
    {
        return $section->key.':'.$locale;
    }
}
