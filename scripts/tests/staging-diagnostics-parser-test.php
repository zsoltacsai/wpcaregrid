<?php

declare(strict_types=1);

require_once __DIR__.'/../staging-diagnostics/read-m12-cache.php';

function assertTest(bool $condition, string $label): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$privacyKeys = [
    'destructive_retention_enabled',
    'profile_anonymization_enabled',
    'backup_deletion_enabled',
    'provider_deletion_enabled',
    'bulk_retention_execution_enabled',
    'synthetic_profile_anonymization_enabled',
];

$fixture = [
    'privacy' => array_fill_keys($privacyKeys, false) + [
        'string_false' => 'false',
        'sensitive_unrelated_value' => 'SYNTHETIC_SECRET_MUST_NOT_BE_EMITTED',
    ],
    'security' => [
        'incidents' => [
            'auto_open' => false,
            'auto_backup' => false,
            'unrelated_secret_like_value' => 'SYNTHETIC_SECRET_MUST_NOT_BE_EMITTED',
        ],
    ],
    'app' => [
        'deployment_environment' => 'staging',
        'deployment_hostname' => 'stg1.wpcaregrid.com',
        'url' => 'https://staging.wpcaregrid.com',
    ],
    'services' => ['synthetic' => ['quoted' => "apostrophe ' and slash \\"]],
];

$parser = new StaticLaravelConfigCacheParser;
$parsed = $parser->parse('<?php return '.var_export($fixture, true).';');
$output = implode("\n", m12FormatValues($parsed));
assertTest(($parsed['privacy'][$privacyKeys[0]] ?? null) === false, 'actual boolean false parsed');
assertTest(str_contains($output, 'M12|destructive_retention_enabled|FALSE'), 'false rendered as FALSE');
assertTest(str_contains($output, 'M12|string_false|UNKNOWN') === false, 'unapproved keys not emitted');
assertTest(! str_contains($output, 'SYNTHETIC_SECRET_MUST_NOT_BE_EMITTED'), 'unrelated sensitive value not emitted');
assertTest(str_contains($output, 'SECURITY|auto_open|FALSE'), 'false auto-open setting rendered');
assertTest(str_contains($output, 'SECURITY|auto_backup|FALSE'), 'false auto-backup setting rendered');
assertTest(str_contains($output, 'IDENTITY|deployment_environment|staging'), 'expected identity rendered');
assertTest(substr_count($output, "\n") === 10, 'exactly eleven approved rows rendered');
assertTest(! str_contains($output, 'SYNTHETIC_SECRET_MUST_NOT_BE_EMITTED'), 'unrelated secret-like settings not emitted');

$ownerOnlyAcl = "user::rw-\ngroup::---\nother::---\n";
$phpFpmAcl = "user::rw-\nuser:www-data:r--\ngroup::---\nmask::r--\nother::---\n";
$groupReadableAcl = "user::rw-\ngroup::r--\nmask::r--\nother::---\n";
$extraReaderAcl = "user::rw-\nuser:nobody:r--\ngroup::---\nmask::r--\nother::---\n";
assertTest(m12CacheAclMatches(0600, $ownerOnlyAcl), 'owner-only cache ACL accepted');
assertTest(m12CacheAclMatches(0640, $phpFpmAcl), 'exact PHP-FPM read ACL accepted');
assertTest(! m12CacheAclMatches(0640, $groupReadableAcl), 'general group read rejected');
assertTest(! m12CacheAclMatches(0640, $extraReaderAcl), 'unexpected named ACL reader rejected');
assertTest(! m12CacheAclMatches(0604, $ownerOnlyAcl), 'world-readable cache rejected');

$missing = $fixture;
unset($missing['privacy']['provider_deletion_enabled']);
$missingOutput = m12FormatValues($missing);
assertTest(in_array('M12|provider_deletion_enabled|UNKNOWN', $missingOutput, true), 'missing gate is UNKNOWN');

$wrongType = $fixture;
$wrongType['privacy']['backup_deletion_enabled'] = 'false';
$wrongTypeOutput = m12FormatValues($wrongType);
assertTest(in_array('M12|backup_deletion_enabled|UNKNOWN', $wrongTypeOutput, true), 'string false is UNKNOWN');

$missingIncident = $fixture;
unset($missingIncident['security']['incidents']['auto_open']);
assertTest(in_array('SECURITY|auto_open|UNKNOWN', m12FormatValues($missingIncident), true), 'missing incident setting is UNKNOWN');

$wrongIncidentParent = $fixture;
$wrongIncidentParent['security'] = 'unsupported';
assertTest(in_array('SECURITY|auto_backup|UNKNOWN', m12FormatValues($wrongIncidentParent), true), 'unsupported incident parent is UNKNOWN');

$wrongIncidentType = $fixture;
$wrongIncidentType['security']['incidents']['auto_backup'] = 'false';
assertTest(in_array('SECURITY|auto_backup|UNKNOWN', m12FormatValues($wrongIncidentType), true), 'string false incident setting is UNKNOWN');

$enabledIncident = $fixture;
$enabledIncident['security']['incidents']['auto_open'] = true;
assertTest(in_array('SECURITY|auto_open|TRUE', m12FormatValues($enabledIncident), true), 'enabled auto-open is surfaced as TRUE');

$mismatch = $fixture;
$mismatch['app']['deployment_hostname'] = 'unsafe-provider-supplied-host.example';
assertTest(in_array('IDENTITY|deployment_hostname|MISMATCH', m12FormatValues($mismatch), true), 'identity mismatch is not disclosed');

$marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'caregrid-m12-parser-must-not-execute-'.bin2hex(random_bytes(8));
@unlink($marker);
$malformedInputs = [
    '<?php return array (',
    '<?php return '.var_export($fixture, true).'; file_put_contents('.var_export($marker, true).', "executed");',
    '<?php return '.var_export($fixture, true).'; $dynamic = true;',
];

foreach ($malformedInputs as $input) {
    try {
        $parser->parse($input);
        assertTest(false, 'malformed or executable cache rejected');
    } catch (RuntimeException) {
        // Expected: token grammar rejects unsupported syntax without executing it.
    }

    assertTest(
        $parser->parse('<?php return '.var_export($fixture, true).';') === $fixture,
        'parser resets after rejecting an input',
    );
}

assertTest(! file_exists($marker), 'unexpected executable content was not run');
@unlink($marker);

try {
    $parser->parse(str_repeat('x', 1_048_577));
    assertTest(false, 'input size limit enforced');
} catch (RuntimeException $exception) {
    assertTest($exception->getMessage() === 'CACHE_TOO_LARGE', 'fixed size failure code');
}

fwrite(STDOUT, "STAGING_DIAGNOSTICS_PARSER=PASS\n");
