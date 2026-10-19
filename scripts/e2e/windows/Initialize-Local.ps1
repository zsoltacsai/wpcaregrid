param([string] $ExpectedCommit = $env:WP_CAREGRID_E2E_EXPECTED_COMMIT)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Identity = & (Join-Path $PSScriptRoot 'Assert-ApprovedConnectorBrowserWorktree.ps1') -ExpectedCommit $ExpectedCommit
$Repo = $Identity.Repository

$E2E = Join-Path $Repo 'storage\e2e'
$Runtime = Join-Path $E2E 'runtime\php-8.4.26'
$Php = Join-Path $Runtime 'php.exe'
$Ini = Join-Path $Runtime 'php.ini'
$Composer = 'C:\laragon\bin\composer\composer.phar'
$ComposerHome = Join-Path $E2E 'composer-home'
$ComposerCache = Join-Path $E2E 'composer-cache'
$Archive = Join-Path $E2E 'cache\php-8.4.26-Win32-vs17-x64.zip'
$ExpectedSha256 = '6e56f0e932e92bfdce208d3a7e6068f6e2f7a19fc2922a5f856fb085f61673f3'
$PhpUrl = 'https://downloads.php.net/~windows/releases/archives/php-8.4.26-Win32-vs17-x64.zip'

New-Item -ItemType Directory -Force -Path $E2E | Out-Null
if (-not (Test-Path $Php)) {
    if (Test-Path $Runtime) { throw "Incomplete PHP runtime exists; refusing to overwrite: $Runtime" }
    New-Item -ItemType Directory -Force -Path (Split-Path $Archive), (Split-Path $Runtime) | Out-Null
    Invoke-WebRequest -Uri $PhpUrl -OutFile $Archive
    $ActualSha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $Archive).Hash.ToLowerInvariant()
    if ($ActualSha256 -ne $ExpectedSha256) { throw 'PHP runtime checksum verification failed.' }
    Expand-Archive -LiteralPath $Archive -DestinationPath $Runtime
}
if (-not (Test-Path $Composer)) { throw "Laragon Composer was not found at $Composer" }

if (-not (Test-Path $Ini)) {
    $ExtensionDir = (Join-Path $Runtime 'ext').Replace('\', '/')
    @"
[PHP]
extension_dir="$ExtensionDir"
date.timezone=UTC
display_errors=Off
log_errors=On
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=sodium
extension=sqlite3
extension=zip
"@ | Set-Content -LiteralPath $Ini -Encoding ascii
}
$PhpVersion = (& $Php -c $Ini -r 'echo PHP_VERSION;').Trim()
if ([version]$PhpVersion -lt [version]'8.4.1') { throw "PHP 8.4.1+ is required; found $PhpVersion" }

$env:COMPOSER_HOME = $ComposerHome
$env:COMPOSER_CACHE_DIR = $ComposerCache
if (-not (Test-Path (Join-Path $Repo 'vendor\autoload.php'))) {
    & $Php -c $Ini $Composer install --no-interaction --prefer-dist --no-progress --no-scripts
    if ($LASTEXITCODE -ne 0) { throw 'Laravel Composer install failed.' }
}
& $Php -c $Ini (Join-Path $Repo 'artisan') package:discover --ansi
if ($LASTEXITCODE -ne 0) { throw 'Laravel package discovery failed.' }

$WordPressDir = Join-Path $Repo 'wordpress'
if (-not (Test-Path (Join-Path $WordPressDir 'vendor\autoload.php'))) {
    Push-Location $WordPressDir
    try {
        & $Php -c $Ini $Composer install --no-interaction --prefer-dist --no-progress --no-scripts
        if ($LASTEXITCODE -ne 0) { throw 'WordPress test dependency install failed.' }
    } finally { Pop-Location }
}

# Laravel browser routes render Vite assets. A healthy /up endpoint does not
# prove the frontend manifest exists, so build from the committed npm lockfile
# before starting HTTP services and fail closed if the manifest is absent.
$Npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
if (-not $Npm) { throw 'Node.js/npm is required; install the committed npm lockfile before local browser E2E.' }
if (-not (Test-Path (Join-Path $Repo 'node_modules\@playwright\test'))) {
    throw 'Locked browser dependencies are missing; run npm ci --ignore-scripts before local browser E2E.'
}
& $Npm.Source run build
if ($LASTEXITCODE -ne 0) { throw 'The locked frontend build failed.' }
if (-not (Test-Path -LiteralPath (Join-Path $Repo 'public\build\manifest.json') -PathType Leaf)) {
    throw 'Vite build completed without producing public/build/manifest.json.'
}

$LaravelEnv = Join-Path $Repo '.env.e2e'
if (-not (Test-Path $LaravelEnv)) {
    $KeyBytes = [byte[]]::new(32)
    [Security.Cryptography.RandomNumberGenerator]::Fill($KeyBytes)
    $AppKey = [Convert]::ToBase64String($KeyBytes)
    @"
APP_NAME="WP CareGrid Connector E2E"
APP_ENV=e2e
APP_KEY=base64:$AppKey
APP_DEBUG=false
APP_URL=http://127.0.0.1:8317
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=single
LOG_LEVEL=error
DB_CONNECTION=sqlite
DB_DATABASE=storage/e2e/laravel.sqlite
DB_URL=
SESSION_DRIVER=database
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
FILESYSTEM_DISK=local
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_ALLOW_LIVE_MODE=false
CONNECTOR_REQUIRE_HTTPS=false
SZAMLAZZ_PROVIDER_ENABLED=false
SZAMLAZZ_DRY_RUN=true
SZAMLAZZ_ISSUANCE_ENABLED=false
SZAMLAZZ_SYNTHETIC_TEST_ENABLED=false
CLOUDFLARE_API_TOKEN=
SECURITY_VULNERABILITY_SOURCE=none
BACKUP_STORAGE=
"@ | Set-Content -LiteralPath $LaravelEnv -Encoding ascii
} elseif (-not (Select-String -LiteralPath $LaravelEnv -SimpleMatch 'APP_NAME="WP CareGrid Connector E2E"' -Quiet)) {
    throw 'An unrelated .env.e2e exists. Refusing to overwrite it.'
}
# Database sessions persist across real browser HTTP requests and stay inside
# this worktree's disposable Laravel SQLite database.
(Get-Content -LiteralPath $LaravelEnv -Raw).Replace("SESSION_DRIVER=array`r`n", "SESSION_DRIVER=database`r`n").Replace("SESSION_DRIVER=array`n", "SESSION_DRIVER=database`n") | Set-Content -LiteralPath $LaravelEnv -Encoding ascii
$EnvValues = @{}
foreach ($Line in Get-Content -LiteralPath $LaravelEnv) {
    if ($Line -match '^([A-Z0-9_]+)=(.*)$') { $EnvValues[$Matches[1]] = $Matches[2].Trim('"') }
}
$RequiredEnv = @{
    APP_ENV = 'e2e'; APP_URL = 'http://127.0.0.1:8317'; APP_DEBUG = 'false'
    DB_CONNECTION = 'sqlite'; DB_DATABASE = 'storage/e2e/laravel.sqlite'; DB_URL = ''
    SESSION_DRIVER = 'database'; CACHE_STORE = 'array'; QUEUE_CONNECTION = 'sync'; MAIL_MAILER = 'array'
    STRIPE_KEY = ''; STRIPE_SECRET = ''; STRIPE_WEBHOOK_SECRET = ''; STRIPE_ALLOW_LIVE_MODE = 'false'
    CONNECTOR_REQUIRE_HTTPS = 'false'; SZAMLAZZ_PROVIDER_ENABLED = 'false'; SZAMLAZZ_ISSUANCE_ENABLED = 'false'; SZAMLAZZ_SYNTHETIC_TEST_ENABLED = 'false'
    CLOUDFLARE_API_TOKEN = ''; SECURITY_VULNERABILITY_SOURCE = 'none'; BACKUP_STORAGE = ''
}
foreach ($Name in $RequiredEnv.Keys) {
    if (-not $EnvValues.ContainsKey($Name) -or $EnvValues[$Name] -ne $RequiredEnv[$Name]) {
        throw "Unsafe .env.e2e setting: $Name. Refusing to continue."
    }
}
if ($EnvValues.APP_KEY -notmatch '^base64:[A-Za-z0-9+/]+={0,2}$') { throw 'Unsafe or missing E2E APP_KEY.' }

$Site = Join-Path $E2E 'wordpress-site'
$SiteMarker = Join-Path $Site '.wpcaregrid-e2e-site'
$Core = Join-Path $WordPressDir 'vendor\johnpbloch\wordpress-core'
$PluginSource = Join-Path $WordPressDir 'wp-caregrid-connector'
if (-not (Test-Path $SiteMarker)) {
    if (Test-Path $Site) { throw "Unmarked WordPress site directory exists; refusing to reuse it: $Site" }
    if (-not (Test-Path (Join-Path $Core 'wp-load.php'))) { throw 'WordPress core package is missing.' }
    New-Item -ItemType Directory -Force -Path $Site | Out-Null
    Copy-Item -Path (Join-Path $Core '*') -Destination $Site -Recurse -Force
    $Content = Join-Path $Site 'wp-content'
    New-Item -ItemType Directory -Force -Path (Join-Path $Content 'plugins'), (Join-Path $Content 'themes'), (Join-Path $Content 'mu-plugins'), (Join-Path $Site 'database') | Out-Null
    Copy-Item -LiteralPath $PluginSource -Destination (Join-Path $Content 'plugins\wp-caregrid-connector') -Recurse
    $ThemeSource = Join-Path $Core 'wp-content\themes\twentytwentyfive'
    if (Test-Path $ThemeSource) { Copy-Item -LiteralPath $ThemeSource -Destination (Join-Path $Content 'themes\twentytwentyfive') -Recurse }
    Copy-Item -LiteralPath (Join-Path $WordPressDir 'vendor\aaemnnosttv\wp-sqlite-db\src\db.php') -Destination (Join-Path $Content 'db.php')

    $SaltBytes = [byte[]]::new(48)
    [Security.Cryptography.RandomNumberGenerator]::Fill($SaltBytes)
    $Salt = [Convert]::ToHexString($SaltBytes).ToLowerInvariant()
    @"
<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
define('WP_HOME', 'http://127.0.0.1:8318');
define('WP_SITEURL', 'http://127.0.0.1:8318');
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', WP_SITEURL . '/wp-content');
define('DB_DIR', __DIR__ . '/database/');
define('DB_FILE', 'wp.sqlite');
define('DB_NAME', 'wpcaregrid_e2e');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
`$table_prefix = 'wp_';
define('AUTH_KEY', '$Salt');
define('SECURE_AUTH_KEY', '$Salt');
define('LOGGED_IN_KEY', '$Salt');
define('NONCE_KEY', '$Salt');
define('AUTH_SALT', '$Salt');
define('SECURE_AUTH_SALT', '$Salt');
define('LOGGED_IN_SALT', '$Salt');
define('NONCE_SALT', '$Salt');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_CAREGRID_API_URL', 'http://127.0.0.1:8317/api/v1/connector');
define('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST', true);
if (!function_exists('wp_mail')) { function wp_mail(`$to, `$subject, `$message, `$headers = '', `$attachments = []) { return true; } }
require_once ABSPATH . 'wp-settings.php';
"@ | Set-Content -LiteralPath (Join-Path $Site 'wp-config.php') -Encoding ascii

    @'
<?php
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre !== false) return $pre;
    $parts = wp_parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true) && in_array($port, [8317, 8318], true)) return false;
    return new WP_Error('e2e_external_http_blocked', 'External HTTP is disabled in the local Connector E2E site.');
}, PHP_INT_MAX, 3);
'@ | Set-Content -LiteralPath (Join-Path $Content 'mu-plugins\e2e-local-http-only.php') -Encoding ascii
    Set-Content -LiteralPath $SiteMarker -Value 'isolated sqlite site; source: Composer WordPress 7.1.x' -Encoding ascii
}
$WpConfigText = Get-Content -Raw -LiteralPath (Join-Path $Site 'wp-config.php')
foreach ($RequiredWpSetting in @("define('WP_HOME', 'http://127.0.0.1:8318')", "define('WP_SITEURL', 'http://127.0.0.1:8318')", "define('WP_CAREGRID_API_URL', 'http://127.0.0.1:8317/api/v1/connector')", "define('WP_HTTP_BLOCK_EXTERNAL', true)", "define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost')", "define('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST', true)", "define('DB_DIR', __DIR__ . '/database/')")) {
    if (-not $WpConfigText.Contains($RequiredWpSetting)) { throw 'WordPress E2E config is not local-only; refusing to continue.' }
}
$WpEgressGuard = Join-Path $Site 'wp-content\mu-plugins\e2e-local-http-only.php'
if (-not (Test-Path $WpEgressGuard)) { throw 'WordPress E2E HTTP egress guard is missing.' }
$SyntheticHomeFilter = @'
add_filter('home_url', static function ($url, $path, $scheme, $blog_id) {
    if (!is_admin() || $path !== '/') return $url;
    $synthetic = get_option('wp_caregrid_e2e_home_url');
    if (!is_string($synthetic) || !preg_match('#^https://e2e-[a-z0-9-]+\.example\.com$#D', $synthetic)) return $url;
    $relative = ltrim((string) $path, '/');
    return rtrim($synthetic, '/') . '/' . $relative;
}, 10, 4);
'@
$LocalConnectorTimeoutFilter = @'
add_filter('http_request_args', static function ($args, $url) {
    $parts = wp_parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = (int) ($parts['port'] ?? 80);
    if (($host === '127.0.0.1' || $host === 'localhost') && $port === 8317) {
        $args['timeout'] = 2;
        $args['redirection'] = 0;
    }
    return $args;
}, PHP_INT_MAX, 2);
'@
$WpEgressGuardText = Get-Content -Raw -LiteralPath $WpEgressGuard
$UpdatedWpEgressGuardText = $WpEgressGuardText.Replace('if (!is_admin()) return $url;', "if (!is_admin() || `$path !== '/') return `$url;")
if ($UpdatedWpEgressGuardText -ne $WpEgressGuardText) {
    Set-Content -LiteralPath $WpEgressGuard -Value $UpdatedWpEgressGuardText -Encoding ascii
    $WpEgressGuardText = $UpdatedWpEgressGuardText
}
if (-not $WpEgressGuardText.Contains('wp_caregrid_e2e_home_url')) {
    Add-Content -LiteralPath $WpEgressGuard -Value $SyntheticHomeFilter -Encoding ascii
}
if (-not $WpEgressGuardText.Contains("timeout'] = 2")) {
    Add-Content -LiteralPath $WpEgressGuard -Value $LocalConnectorTimeoutFilter -Encoding ascii
}

# Serve WordPress with Laragon's existing Apache/PHP 8.3 module. It is a
# separate Apache config and process bound only to loopback; the shared
# Laragon configuration and services are never started or edited.
$Apache = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18'
$Php83 = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64'
$ApacheWork = Join-Path $E2E 'apache'
$ApacheConfig = Join-Path $ApacheWork 'httpd.conf'
if (-not (Test-Path (Join-Path $Apache 'bin\httpd.exe')) -or -not (Test-Path (Join-Path $Apache 'bin\php8apache2_4.dll'))) {
    throw 'The discovered Laragon Apache/PHP module is unavailable.'
}
New-Item -ItemType Directory -Force -Path (Join-Path $ApacheWork 'php83') | Out-Null
if (-not (Test-Path (Join-Path $ApacheWork 'php83\php.ini'))) {
    $ExtensionDir83 = (Join-Path $Php83 'ext').Replace('\', '/')
    @"
[PHP]
extension_dir="$ExtensionDir83"
date.timezone=UTC
display_errors=Off
log_errors=On
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sodium
extension=sqlite3
"@ | Set-Content -LiteralPath (Join-Path $ApacheWork 'php83\php.ini') -Encoding ascii
}
if (-not (Test-Path $ApacheConfig)) {
    $ApachePath = $Apache.Replace('\', '/')
    $SitePath = $Site.Replace('\', '/')
    $ApacheWorkPath = $ApacheWork.Replace('\', '/')
    @"
ServerRoot "$ApachePath"
Listen 127.0.0.1:8318
ServerName 127.0.0.1
AccessFileName .wpcaregrid-e2e.htaccess
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule php_module bin/php8apache2_4.dll
PHPIniDir "$ApacheWorkPath/php83"
AddHandler application/x-httpd-php .php
DirectoryIndex index.php index.html
DocumentRoot "$SitePath"
<Directory "$SitePath">
    AllowOverride None
    Options FollowSymLinks
    Require all granted
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.php$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.php [L]
</Directory>
TypesConfig conf/mime.types
ErrorLog "$ApacheWorkPath/error.log"
PidFile "$ApacheWorkPath/httpd.pid"
LogFormat "%h %l %u %t \"%r\" %>s %b" common
CustomLog "$ApacheWorkPath/access.log" common
"@ | Set-Content -LiteralPath $ApacheConfig -Encoding ascii
}
& (Join-Path $Apache 'bin\httpd.exe') -d $Apache -f $ApacheConfig -t
if ($LASTEXITCODE -ne 0) { throw 'Isolated Apache configuration validation failed.' }
$ApacheConfigText = Get-Content -Raw -LiteralPath $ApacheConfig
$ExpectedListen = 'Listen 127.0.0.1:8318'
$ExpectedDocumentRoot = 'DocumentRoot "' + $Site.Replace('\', '/') + '"'
if (-not $ApacheConfigText.Contains($ExpectedListen) -or -not $ApacheConfigText.Contains($ExpectedDocumentRoot) -or -not $ApacheConfigText.Contains('AccessFileName .wpcaregrid-e2e.htaccess')) {
    throw 'Apache is not configured for this loopback-only E2E site; refusing to start.'
}
if ($ApacheConfigText -match '(?m)^\s*Listen\s+(?!127\.0\.0\.1:8318\s*$)') { throw 'Apache contains a non-loopback or unexpected listener.' }

$LaravelDb = Join-Path $E2E 'laravel.sqlite'
if (-not (Test-Path $LaravelDb)) { New-Item -ItemType File -Path $LaravelDb | Out-Null }

$CredentialFile = Join-Path $E2E 'credentials.json'
if (-not (Test-Path $CredentialFile)) {
    $PasswordBytes = [byte[]]::new(32)
    [Security.Cryptography.RandomNumberGenerator]::Fill($PasswordBytes)
    $Password = [Convert]::ToBase64String($PasswordBytes).TrimEnd('=').Replace('+', 'A').Replace('/', 'B')
    [ordered]@{ wordpress_admin = 'e2e-admin'; wordpress_password = $Password; wordpress_email = 'e2e-admin@example.invalid' } |
        ConvertTo-Json | Set-Content -LiteralPath $CredentialFile -Encoding ascii
}
$Credentials = Get-Content -Raw -LiteralPath $CredentialFile | ConvertFrom-Json
$env:WP_CAREGRID_E2E_ADMIN_PASSWORD = $Credentials.wordpress_password
$env:APP_ENV = 'e2e'
$env:WP_CAREGRID_TEST_MYSQL = ''

$InstallScript = Join-Path $E2E 'install-wordpress.php'
@'
<?php
define('WP_INSTALLING', true);
require getenv('WP_CAREGRID_E2E_SITE').'/wp-config.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (!is_blog_installed()) {
    $installed = wp_install('CareGrid Synthetic E2E', 'e2e-admin', 'e2e-admin@example.invalid', false, '', getenv('WP_CAREGRID_E2E_ADMIN_PASSWORD'));
    if (is_wp_error($installed) || empty($installed['user_id'])) { fwrite(STDERR, "WordPress installation failed.\n"); exit(1); }
}
update_option('home', WP_HOME);
update_option('siteurl', WP_SITEURL);
update_option('permalink_structure', '/%postname%/');
if (!is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php')) {
    $activated = activate_plugin('wp-caregrid-connector/wp-caregrid-connector.php');
    if (is_wp_error($activated)) { fwrite(STDERR, "Connector activation failed.\n"); exit(1); }
}
if (!is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php')) { fwrite(STDERR, "Connector is not active.\n"); exit(1); }
echo 'WordPress '.get_bloginfo('version').'; connector active; SQLite database.'.PHP_EOL;
'@ | Set-Content -LiteralPath $InstallScript -Encoding ascii
$env:WP_CAREGRID_E2E_SITE = $Site
& $Php -c $Ini $InstallScript
if ($LASTEXITCODE -ne 0) { throw 'Local WordPress initialization failed.' }

& $Php -c $Ini (Join-Path $Repo 'artisan') migrate --env=e2e --force
if ($LASTEXITCODE -ne 0) { throw 'Laravel E2E SQLite migrations failed.' }

Write-Output "Initialized isolated Connector E2E environment in $E2E"
Write-Output 'Laravel database: storage/e2e/laravel.sqlite'
Write-Output 'WordPress database: storage/e2e/wordpress-site/database/wp.sqlite'
Write-Output 'WordPress admin credentials are stored only in ignored storage/e2e/credentials.json.'
