<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/** Builds the private plugin ZIP customers download from their account. */
class PackageConnector extends Command
{
    protected $signature = 'connector:package';

    protected $description = 'Build the WP CareGrid Connector plugin ZIP';

    public function handle(): int
    {
        $source = config('connector.plugin_source');
        $header = (string) file_get_contents("{$source}/wp-caregrid-connector.php");
        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $match);
        $version = $match[1] ?? null;

        if ($version !== config('connector.latest_version')) {
            $this->error("Plugin version {$version} does not match connector.latest_version ".config('connector.latest_version').'.');

            return self::FAILURE;
        }

        @mkdir(config('connector.package_path'), 0775, true);
        $target = config('connector.package_path')."/wp-caregrid-connector-{$version}.zip";

        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $zip->addFile($file->getPathname(), "wp-caregrid-connector/{$relative}");
        }

        $zip->close();
        $this->info("Built {$target}");

        return self::SUCCESS;
    }
}
