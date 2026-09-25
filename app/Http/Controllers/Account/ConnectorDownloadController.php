<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The connector plugin ZIP, for logged-in customers only (private distribution). */
class ConnectorDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $version = config('connector.latest_version');
        $path = config('connector.package_path')."/wp-caregrid-connector-{$version}.zip";

        abort_unless(is_file($path), 404);

        return response()->download($path, "wp-caregrid-connector-{$version}.zip");
    }
}
