<?php

namespace App\Http\Controllers\Account;

use App\Backups\BackupObjectName;
use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Backups\CustomerDownloads\BackupDownloadRefused;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Models\User;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Session-authenticated, bounded range downloads for one verified object. */
final class BackupDownloadController extends Controller
{
    public function create(Request $request, Website $website, Backup $backup, string $object, BackupDownloadGrants $grants): JsonResponse
    {
        $name = BackupObjectName::tryFrom($object);
        if (! $name) {
            return response()->json(['code' => 'NOT_FOUND'], 404);
        }
        $backupObject = $backup->objects()->where('name', $name->value)->first();
        if (! $backupObject) {
            return response()->json(['code' => 'NOT_FOUND'], 404);
        }

        try {
            $grant = $grants->create($request->user(), $website, $backup, $backupObject);
            return response()->json([
                'grant' => $grant->uuid,
                'status' => $grant->status,
                'expires_at' => $grant->expires_at?->toIso8601String(),
                'status_url' => route('account.backup-downloads.status', $grant),
                'content_url' => $grant->status === BackupDownloadGrant::READY ? route('account.backup-downloads.content', $grant) : null,
            ], $grant->status === BackupDownloadGrant::READY ? 200 : 202);
        } catch (BackupDownloadRefused $e) {
            return $this->failure($e);
        } catch (Throwable) {
            return response()->json(['code' => 'DOWNLOAD_UNAVAILABLE'], 503);
        }
    }

    public function status(Request $request, BackupDownloadGrant $grant, BackupDownloadGrants $grants): JsonResponse
    {
        try {
            $grant = $grants->status($request->user(), $grant);
            return response()->json([
                'grant' => $grant->uuid,
                'status' => $grant->status,
                'failure_code' => $grant->status === BackupDownloadGrant::FAILED ? $grant->failure_code : null,
                'expires_at' => $grant->expires_at?->toIso8601String(),
                'content_url' => $grant->status === BackupDownloadGrant::READY ? route('account.backup-downloads.content', $grant) : null,
            ]);
        } catch (BackupDownloadRefused $e) {
            return $this->failure($e);
        }
    }

    public function content(Request $request, BackupDownloadGrant $grant, BackupDownloadGrants $grants): StreamedResponse|JsonResponse
    {
        try {
            $range = $grants->readRange($request->user(), $grant, (string) $request->header('Range', ''));
        } catch (BackupDownloadRefused $e) {
            return $this->failure($e, $grant->object_size);
        }

        $filename = 'backup-'.$grant->backup->uuid.'-'.$grant->backupObject->objectName()->filename();
        $response = response()->stream(function () use ($range, $grant, $grants): void {
            $sent = 0;
            try {
                if (! connection_aborted()) {
                    echo $range->bytes;
                    $sent = strlen($range->bytes);
                }
            } finally {
                $grants->recordResponse($grant, $sent, $sent === $range->length && ! connection_aborted());
            }
        }, 206, [
            'Accept-Ranges' => 'bytes',
            'Content-Range' => $range->contentRange(),
            'Content-Length' => (string) $range->length,
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => '"'.$grant->object_sha256.'"',
        ]);

        return $response;
    }

    public function revoke(Request $request, BackupDownloadGrant $grant, BackupDownloadGrants $grants): JsonResponse
    {
        try {
            $grants->revoke($request->user(), $grant);
            return response()->json(['status' => BackupDownloadGrant::REVOKED]);
        } catch (BackupDownloadRefused $e) {
            return $this->failure($e);
        }
    }

    private function failure(BackupDownloadRefused $exception, ?int $total = null): JsonResponse
    {
        Log::notice('Customer backup download refused.', ['actor_user_id' => auth()->id(), 'code' => $exception->errorCode]);
        $status = match ($exception->errorCode) {
            'NOT_FOUND' => 404,
            'RANGE_INVALID', 'RANGE_TOO_LARGE' => 416,
            'RANGE_CONFIGURATION_INVALID' => 503,
            'BACKUP_UNAVAILABLE', 'OBJECT_UNAVAILABLE', 'GRANT_EXPIRED', 'GRANT_REVOKED', 'GRANT_UNAVAILABLE' => 410,
            'OBJECT_INTEGRITY_MISMATCH', 'OBJECT_SIZE_MISMATCH', 'STORAGE_UNAVAILABLE', 'STORAGE_UNSUPPORTED' => 503,
            default => 409,
        };
        return response()->json(['code' => $exception->errorCode], $status, $status === 416 ? ['Content-Range' => 'bytes */'.max(0, $total ?? 0)] : []);
    }
}
