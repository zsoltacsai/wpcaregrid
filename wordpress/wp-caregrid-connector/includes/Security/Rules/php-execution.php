<?php

/*
 * Dynamic execution of decoded or request-supplied data. Indicators, not
 * verdicts: each match is a SUSPICIOUS_FILE with this severity/confidence.
 * Plain eval(), base64_decode() or exec() on their own are NOT rules:
 * legitimate code uses them.
 */
return [
    [
        'code' => 'PHP_EVAL_DECODED',
        'name' => 'eval() of decoded data',
        'description' => 'Code is decoded at runtime and executed with eval(), a common way to hide malicious code.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\be[v]al\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|rawurldecode|hex2bin|convert_uudecode)\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_REQUEST_EXECUTION',
        'name' => 'Request data executed',
        'description' => 'Data from the web request is passed straight to a function that runs code or shell commands (webshell behaviour).',
        'severity' => 'critical',
        'confidence' => 'high',
        'applies' => 'php',
        'pattern' => '/\b(?:e[v]al|a[s]sert|s[y]stem|shell_[e]xec|pass[t]hru|e[x]ec|p[o]pen|proc_[o]pen|pcntl_[e]xec)\s*\(\s*(?:@\s*)?(?:(?:stripslashes|base64_decode|trim|urldecode)\s*\(\s*)?\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_REQUEST_AS_FUNCTION',
        'name' => 'Request data called as a function',
        'description' => 'A value from the web request is used as the name of a function to call.',
        'severity' => 'critical',
        'confidence' => 'high',
        'applies' => 'php',
        'pattern' => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[\s*[^\]]{1,40}\]\s*\(\s*(?:@\s*)?\$_(?:GET|POST|REQUEST|COOKIE)\b/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_PREG_REPLACE_EVAL',
        'name' => 'preg_replace with the /e modifier',
        'description' => 'The removed /e modifier evaluates replacement text as code; only old malware still uses it.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\bpreg_[r]eplace\s*\(\s*[\'"]\/[^\'"\n]{0,200}\/[a-df-zA-Z]*e[a-zA-Z]*[\'"]\s*,/',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_CREATE_FUNCTION_DECODED',
        'name' => 'create_function() with decoded code',
        'description' => 'An anonymous function is built from decoded text.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\bcreate_[f]unction\s*\([^;]{0,300}(?:base64_decode|str_rot13|gzinflate)\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_ASSERT_DECODED',
        'name' => 'assert() of decoded data',
        'description' => 'assert() evaluating decoded text works like eval().',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\ba[s]sert\s*\(\s*(?:@\s*)?(?:base64_decode|str_rot13|gzinflate)\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_VARIABLE_DECODER_CALL',
        'name' => 'Decoder called through a variable',
        'description' => 'The name of an execution or decoding function is stored in a variable and called, hiding the call.',
        'severity' => 'medium',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\$\w{1,40}\s*=\s*[\'"](?:e[v]al|a[s]sert|base64_[d]ecode|gz[i]nflate|str_[r]ot13|create_[f]unction)[\'"]\s*;[\s\S]{0,200}?\$\w{1,40}\s*\(/i',
        'since' => '2026.10.1',
    ],
];
