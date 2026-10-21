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
assertTest(str_contains($output, 'IDENTITY|deployment_environment|staging'), 'expected identity rendered');

$missing = $fixture;
unset($missing['privacy']['provider_deletion_enabled']);
$missingOutput = m12FormatValues($missing);
assertTest(in_array('M12|provider_deletion_enabled|UNKNOWN', $missingOutput, true), 'missing gate is UNKNOWN');

$wrongType = $fixture;
$wrongType['privacy']['backup_deletion_enabled'] = 'false';
$wrongTypeOutput = m12FormatValues($wrongType);
assertTest(in_array('M12|backup_deletion_enabled|UNKNOWN', $wrongTypeOutput, true), 'string false is UNKNOWN');

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
