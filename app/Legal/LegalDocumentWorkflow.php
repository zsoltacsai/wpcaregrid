<?php

namespace App\Legal;

use App\Content\HtmlSanitizer;
use App\Models\Customer;
use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class LegalDocumentWorkflow
{
    /** @param array{document_type:string,locale:string,title:string,body?:string,acceptance_mode?:string,audiences?:list<string>,effective_at?:string|null} $data */
    public function createDraft(array $data, User $actor): LegalDocumentVersion
    {
        return DB::transaction(function () use ($data, $actor): LegalDocumentVersion {
            $versions = LegalDocumentVersion::query()
                ->where('document_type', $data['document_type'])
                ->where('locale', $data['locale'])
                ->lockForUpdate()
                ->get(['id', 'version']);

            $version = ((int) $versions->max('version')) + 1;
            $document = LegalDocumentVersion::query()->create([
                'document_type' => $data['document_type'],
                'version' => $version,
                'locale' => $data['locale'],
                'title' => trim($data['title']),
                'body' => (string) ($data['body'] ?? ''),
                'status' => 'draft',
                'acceptance_mode' => $data['acceptance_mode'] ?? 'disabled',
                'audiences' => $data['audiences'] ?? ($data['document_type'] === 'dpa' ? [] : ['all']),
                'effective_at' => filled($data['effective_at'] ?? null) ? Carbon::parse($data['effective_at'], 'UTC')->utc() : null,
                'created_by_user_id' => $actor->id,
                'prepared_at' => Carbon::now('UTC'),
            ]);

            AdminAudit::record($actor, 'legal.document.draft_created', 'legal_document_version', $document->uuid, [
                'document_type' => $document->document_type,
                'locale' => $document->locale,
                'version' => $document->version,
            ]);

            return $document;
        }, attempts: 3);
    }

    /** @param array{title:string,body:string,acceptance_mode:string,audiences:list<string>,effective_at?:string|null} $data */
    public function updateDraft(LegalDocumentVersion $document, array $data, User $actor): LegalDocumentVersion
    {
        return DB::transaction(function () use ($document, $data, $actor): LegalDocumentVersion {
            $locked = LegalDocumentVersion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $locked->fill([
                'title' => trim($data['title']),
                'body' => $data['body'],
                'acceptance_mode' => $data['acceptance_mode'],
                'audiences' => $data['audiences'],
                'effective_at' => filled($data['effective_at'] ?? null) ? Carbon::parse($data['effective_at'], 'UTC')->utc() : null,
            ])->save();

            AdminAudit::record($actor, 'legal.document.draft_updated', 'legal_document_version', $locked->uuid, [
                'document_type' => $locked->document_type,
                'locale' => $locked->locale,
                'version' => $locked->version,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function approve(LegalDocumentVersion $document, string $reference, User $actor): LegalDocumentVersion
    {
        return DB::transaction(function () use ($document, $reference, $actor): LegalDocumentVersion {
            $locked = LegalDocumentVersion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            if (trim($locked->title) === '' || trim(strip_tags($locked->body)) === '' || $locked->effective_at === null) {
                throw ValidationException::withMessages(['document' => 'Title, body and UTC effective date are required before review can be recorded.']);
            }
            if ($locked->document_type === 'dpa' && ! in_array('all', $locked->audiences ?? [], true)) {
                throw ValidationException::withMessages(['document' => 'The DPA audience must be explicitly approved for public access before review can be recorded.']);
            }

            $locked->forceFill([
                'body' => HtmlSanitizer::clean($locked->body),
                'content_sha256' => hash('sha256', HtmlSanitizer::clean($locked->body)),
                'approval_reference' => trim($reference),
                'approved_by_user_id' => $actor->id,
                'approved_at' => Carbon::now('UTC'),
                'status' => 'approved',
            ])->save();

            AdminAudit::record($actor, 'legal.document.approval_recorded', 'legal_document_version', $locked->uuid, [
                'document_type' => $locked->document_type,
                'locale' => $locked->locale,
                'version' => $locked->version,
                'reference_attached' => true,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function publish(LegalDocumentVersion $document, User $actor): LegalDocumentVersion
    {
        return DB::transaction(function () use ($document, $actor): LegalDocumentVersion {
            $locked = LegalDocumentVersion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved' || blank($locked->approval_reference) || blank($locked->content_sha256)) {
                throw ValidationException::withMessages(['document' => 'A recorded approval reference and immutable approved content are required before publication.']);
            }

            $locked->forceFill([
                'status' => 'published',
                'published_by_user_id' => $actor->id,
                'published_at' => Carbon::now('UTC'),
            ])->save();

            AdminAudit::record($actor, 'legal.document.published', 'legal_document_version', $locked->uuid, [
                'document_type' => $locked->document_type,
                'locale' => $locked->locale,
                'version' => $locked->version,
                'effective_at' => $locked->effective_at?->utc()->toIso8601String(),
                'content_sha256' => $locked->content_sha256,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function retire(LegalDocumentVersion $document, User $actor): LegalDocumentVersion
    {
        return DB::transaction(function () use ($document, $actor): LegalDocumentVersion {
            $locked = LegalDocumentVersion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'published') {
                throw new LogicException('Only a published legal document version can be retired.');
            }

            $locked->forceFill([
                'status' => 'retired',
                'retired_by_user_id' => $actor->id,
                'retired_at' => Carbon::now('UTC'),
            ])->save();

            AdminAudit::record($actor, 'legal.document.retired', 'legal_document_version', $locked->uuid, [
                'document_type' => $locked->document_type,
                'locale' => $locked->locale,
                'version' => $locked->version,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function accept(LegalDocumentVersion $document, User $actor, ?Customer $customer, string $source): LegalDocumentAcceptance
    {
        if ($source !== 'customer_legal_area'
            || $document->acceptance_mode !== 'acceptance_required_before_account_use') {
            throw ValidationException::withMessages(['document' => 'This version does not request explicit acceptance.']);
        }

        return DB::transaction(function () use ($document, $actor, $customer, $source): LegalDocumentAcceptance {
            $locked = LegalDocumentVersion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $current = app(LegalDocumentCatalog::class)->current($locked->document_type, $locked->locale);
            if ($current?->id !== $locked->id || blank($locked->content_sha256) || blank($locked->approval_reference)) {
                throw ValidationException::withMessages(['document' => 'This document version is no longer current or is not approved for acceptance.']);
            }

            $acceptance = LegalDocumentAcceptance::query()->firstOrCreate(
                ['user_id' => $actor->id, 'legal_document_version_id' => $locked->id],
                [
                    'customer_id' => $customer?->id,
                    'document_type' => $locked->document_type,
                    'locale' => $locked->locale,
                    'content_sha256' => $locked->content_sha256,
                    'source' => $source,
                    'accepted_at' => Carbon::now('UTC'),
                ],
            );

            return $acceptance;
        }, attempts: 3);
    }

    private function assertDraft(LegalDocumentVersion $document): void
    {
        if ($document->status !== 'draft') {
            throw new LogicException('Only draft legal documents can be edited or submitted for approval.');
        }
    }
}
