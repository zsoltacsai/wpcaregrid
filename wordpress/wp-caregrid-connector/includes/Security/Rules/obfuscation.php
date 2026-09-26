<?php

/*
 * Obfuscation. Weak on their own (minified or generated code can look like
 * this), so they are low confidence; together with other indicators they
 * raise a file's confidence (see FileInspector).
 */
return [
    [
        'code' => 'PHP_DECODER_CHAIN',
        'name' => 'Nested decoders',
        'description' => 'Data is decoded several times in a row (e.g. gzinflate of base64), typical of packed malware.',
        'severity' => 'medium',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\b(?:gz[i]nflate|gz[u]ncompress|gz[d]ecode)\s*\(\s*(?:@\s*)?(?:base64_[d]ecode|str_[r]ot13|str[r]ev)\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_LONG_ENCODED_STRING',
        'name' => 'Very long encoded string',
        'description' => 'A string of several kilobytes of base64-like text inside PHP code.',
        'severity' => 'medium',
        'confidence' => 'low',
        'applies' => 'php',
        'pattern' => '/[\'"][A-Za-z0-9+\/]{3000,}={0,2}[\'"]/',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_HEX_ESCAPES',
        'name' => 'Long hex-escaped string',
        'description' => 'Text written as long runs of \\x escapes to hide what it says.',
        'severity' => 'medium',
        'confidence' => 'low',
        'applies' => 'php',
        'pattern' => '/(?:\\\\x[0-9a-fA-F]{2}){40,}/',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_CHR_CONCATENATION',
        'name' => 'String built from character codes',
        'description' => 'A string assembled from many chr() calls to hide what it says.',
        'severity' => 'medium',
        'confidence' => 'low',
        'applies' => 'php',
        'pattern' => '/(?:\bc[h]r\s*\(\s*\d{1,3}\s*\)\s*\.\s*){15,}/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_GLOBALS_INDIRECTION',
        'name' => 'Obfuscated $GLOBALS calls',
        'description' => 'Functions called through indexed $GLOBALS entries, a pattern of generated obfuscators.',
        'severity' => 'medium',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/(?:\$G[L]OBALS\s*\[\s*[\'"][^\'"]{1,20}[\'"]\s*\]\s*\[\s*\d+\s*\][\s\S]{0,60}?){8,}/',
        'since' => '2026.10.1',
    ],
];
