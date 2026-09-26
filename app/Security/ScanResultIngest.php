<?php

namespace App\Security;

use App\Connector\JobType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates what a connector reports for one SECURITY_SCAN part. Strict:
 * findings carry codes, relative paths and metadata only. Evidence keys are
 * an allowlist; anything else (file contents, option values, e-mail
 * addresses, hashes of passwords…) is dropped before it is stored. Text is
 * kept as plain text (PlainText) and escaped when rendered.
 */
final class ScanResultIngest
{
    /** Evidence keys a finding may carry. Values: short scalars or short lists of scalars. */
    public const EVIDENCE_KEYS = [
        'sha256', 'size', 'modified_at', 'permissions', 'rules', 'lines', 'matches', 'integrity', 'reference',
        'version', 'plugin', 'theme', 'slug', 'directory', 'extension', 'file_count',
        'option_name', 'post_ids', 'post_type', 'user_id', 'user_login', 'registered', 'roles', 'new_admin',
        'hook', 'schedule', 'next_run', 'callbacks', 'indicators', 'setting', 'environment', 'managed',
    ];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $count = ['nullable', 'integer', 'min:0'];

        return [
            'scan_uuid' => ['required', 'uuid'],
            'part' => ['required', 'integer', 'min:1'],
            'complete' => ['required', 'boolean'],
            'scanner_version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+/'],
            'rules_version' => ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'coverage' => ['present', 'array'],
            'coverage.integrity' => ['nullable', 'array'],
            'coverage.integrity.status' => ['required_with:coverage.integrity', Rule::in(['completed', 'partial', 'unavailable', 'failed'])],
            'coverage.integrity.core' => ['nullable', Rule::in(['verified', 'modified', 'unavailable'])],
            'coverage.integrity.verified' => $count,
            'coverage.integrity.modified' => $count,
            'coverage.integrity.missing' => $count,
            'coverage.integrity.extra' => $count,
            'coverage.integrity.unavailable' => $count,
            'coverage.malware' => ['nullable', 'array'],
            'coverage.malware.status' => ['required_with:coverage.malware', Rule::in(['completed', 'partial', 'unavailable', 'failed'])],
            'coverage.malware.files_scanned' => $count,
            'coverage.malware.bytes_scanned' => $count,
            'coverage.malware.skipped' => $count,
            'coverage.malware.database' => ['nullable', Rule::in(['completed', 'unavailable', 'failed'])],
            'findings' => ['present', 'array', 'max:'.(int) config('security.scan.max_findings_per_part')],
            'findings.*.type' => ['required', Rule::in(array_map(fn (FindingType $t) => $t->value, FindingType::reportable()))],
            'findings.*.code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'findings.*.severity' => ['nullable', Rule::enum(Severity::class)],
            'findings.*.confidence' => ['required', Rule::in(array_map(fn (Confidence $c) => $c->value, Confidence::reportable()))],
            'findings.*.title' => ['required', 'string', 'max:191'],
            'findings.*.description' => ['nullable', 'string', 'max:1000'],
            'findings.*.component' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9 ._:\/@-]+$/'],
            'findings.*.path' => ['nullable', 'string', 'regex:'.JobType::SITE_PATH, 'not_regex:'.JobType::SITE_PATH_UNSAFE],
            'findings.*.evidence' => ['nullable', 'array'],
            'admins' => ['nullable', 'array', 'max:500'],
            'admins.*.id' => ['required', 'integer', 'min:1'],
            'admins.*.login' => ['required', 'string', 'max:60'],
            'admins.*.registered' => ['nullable', 'date'],
            'admins.*.roles' => ['present', 'array', 'max:10'],
            'admins.*.roles.*' => ['string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'admins.*.managed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  data.security from the job result
     * @return array{part: int, complete: bool, scanner_version: string, rules_version: string, coverage: array, findings: list<ReportedFinding>, admins: ?array}
     *
     * @throws ValidationException
     */
    public function parse(array $data): array
    {
        $valid = Validator::make($data, self::rules())->validate();

        return [
            'part' => (int) $valid['part'],
            'complete' => (bool) $valid['complete'],
            'scanner_version' => $valid['scanner_version'],
            'rules_version' => $valid['rules_version'],
            'coverage' => array_intersect_key($valid['coverage'], array_flip(['integrity', 'malware'])),
            'findings' => array_map(fn (array $f) => new ReportedFinding(
                type: FindingType::from($f['type']),
                code: $f['code'],
                severity: isset($f['severity']) ? Severity::from($f['severity']) : null,
                confidence: Confidence::from($f['confidence']),
                title: PlainText::clean($f['title'], 191),
                description: isset($f['description']) ? PlainText::clean($f['description'], 1000) : null,
                component: $f['component'] ?? null,
                path: $f['path'] ?? null,
                evidence: self::evidence($f['evidence'] ?? []),
            ), array_values($valid['findings'])),
            'admins' => isset($valid['admins']) ? array_values($valid['admins']) : null,
        ];
    }

    /** @return array<string, scalar|list<scalar>> */
    public static function evidence(array $evidence): array
    {
        $clean = [];
        foreach (array_intersect_key($evidence, array_flip(self::EVIDENCE_KEYS)) as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = PlainText::clean($value, 191);
            } elseif (is_array($value) && array_is_list($value)) {
                $clean[$key] = array_map(fn ($v) => is_string($v) ? PlainText::clean($v, 64) : $v, array_values(array_filter(array_slice($value, 0, 20), fn ($v) => is_scalar($v))));
            }
        }

        if (isset($clean['sha256']) && ! preg_match('/^[a-f0-9]{64}$/', (string) $clean['sha256'])) {
            unset($clean['sha256']);
        }

        return $clean;
    }
}
