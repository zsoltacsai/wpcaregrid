<?php

namespace App\Http\Controllers\Account;

use App\Connector\ConnectorPackageArtifact;
use App\Http\Controllers\Controller;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The connector plugin ZIP, for logged-in customers only (private distribution). */
class ConnectorDownloadController extends Controller
{
    public function __invoke(ConnectorPackageArtifact $artifact): BinaryFileResponse
    {
        try {
            $package = $artifact->verify();
        } catch (RuntimeException) {
            abort(404);
        }

        $response = response()->download($package['path'], "wp-caregrid-connector-{$package['version']}.zip");
        $response->headers->set('X-Connector-Version', $package['version']);
        $response->headers->set('X-Connector-Source-SHA256', $package['source_sha256']);
        $response->headers->set('X-Connector-Package-SHA256', $package['package_sha256']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
