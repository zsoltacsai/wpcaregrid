<?php

/*
 * Webshells and loaders: markers of well-known shells, remote code
 * download-and-execute, and PHP loaded from image files.
 */
return [
    [
        'code' => 'WEBSHELL_MARKER',
        'name' => 'Known webshell marker',
        'description' => 'Text that identifies a well-known webshell.',
        'severity' => 'critical',
        'confidence' => 'high',
        'applies' => 'php',
        'pattern' => '/\b(?:Files[M]an|WSO[s]etcookie|b37[4]k|c9[9]shell|r5[7]shell|IndoX[p]loit|Leaf[M]ailer|Alfa[_ ]?Tea[m]\b|Uname\s*:\s*<[\/]?b>)/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_REMOTE_CODE_EXECUTION',
        'name' => 'Downloads code and runs it',
        'description' => 'Content is fetched from a URL and then executed.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\b(?:file_[g]et_contents|curl_[i]nit|f[o]pen)\s*\(\s*[\'"]https?:\/\/[^\'"\s]{4,200}[\'"][\s\S]{0,300}?\be[v]al\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_WRITES_REMOTE_PHP',
        'name' => 'Writes downloaded or decoded content to a PHP file',
        'description' => 'A PHP file is created from downloaded or decoded content (dropper behaviour).',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        'pattern' => '/\bfile_[p]ut_contents\s*\([^,;]{0,160}\.ph(?:p\d?|tml)[\'"]?\s*\)?\s*,\s*(?:@\s*)?(?:file_[g]et_contents|base64_[d]ecode|gz[i]nflate)\s*\(/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'PHP_INCLUDES_IMAGE_FILE',
        'name' => 'Includes an image or icon file as code',
        'description' => 'include/require of an image or icon file: hidden loaders store code in files that look like images.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'php',
        // A real include statement, not "wp-includes/" or includes_url(): the keyword is
        // followed directly by the path expression.
        'pattern' => '/(?<![\w\/-])(?:inc[l]ude|req[u]ire)(?:_once)?(?:\s+|\s*\(\s*)(?:[\'"]|\$|__DIR__|ABSPATH|dirname\s*\()[^;\n]{0,120}\.(?:ico|png|jpe?g|gif|bmp|webp)[\'"]\s*\)?\s*;/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'HTACCESS_AUTO_PREPEND',
        'name' => '.htaccess loads a file before every request',
        'description' => 'php_value auto_prepend_file / auto_append_file makes PHP run a file on every request.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'htaccess',
        'pattern' => '/php_[v]alue\s+auto_(?:prepend|append)_file/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'HTACCESS_PHP_HANDLER',
        'name' => '.htaccess runs non-PHP files as PHP',
        'description' => 'Images or text files are configured to be executed as PHP.',
        'severity' => 'high',
        'confidence' => 'medium',
        'applies' => 'htaccess',
        'pattern' => '/(?:Add[H]andler|Add[T]ype|Set[H]andler)\s+[^\n]*php[^\n]*(?:\.jpe?g|\.png|\.gif|\.ico|\.txt|\.html?)\b/i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'HTACCESS_SEARCH_REDIRECT',
        'name' => '.htaccess redirects search engine visitors',
        'description' => 'Visitors or crawlers from search engines are sent to another site (SEO spam).',
        'severity' => 'medium',
        'confidence' => 'medium',
        'applies' => 'htaccess',
        'pattern' => '/RewriteCond\s+%\{HTTP_(?:REFERER|USER_AGENT)\}[^\n]*(?:goo[g]le|bi[n]g|yah[o]o|yan[d]ex)[\s\S]{0,300}?RewriteRule[^\n]+https?:\/\//i',
        'since' => '2026.10.1',
    ],
    [
        'code' => 'SVG_SCRIPT',
        'name' => 'Script inside an uploaded SVG',
        'description' => 'An SVG image contains JavaScript, which runs when the file is opened directly.',
        'severity' => 'medium',
        'confidence' => 'medium',
        'applies' => 'svg',
        'pattern' => '/<s[c]ript\b|\bon(?:load|error|click|mouseover)\s*=\s*["\']/i',
        'since' => '2026.10.1',
    ],
];
