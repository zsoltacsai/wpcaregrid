<?php

namespace App\Connector;

use Illuminate\Http\Request;

/** Fail closed on oversized or pathologically nested Connector JSON requests. */
final class PayloadBounds
{
    public static function assert(Request $request, int $maxBodyBytes, int $maxDepth, int $maxMembers, int $maxScalarBytes): void
    {
        $contentLength = $request->server('CONTENT_LENGTH');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $maxBodyBytes) {
            throw new ConnectorError('PAYLOAD_TOO_LARGE', 'The request payload exceeds the supported limit.', 413);
        }

        $body = $request->getContent();
        if (strlen($body) > $maxBodyBytes) {
            throw new ConnectorError('PAYLOAD_TOO_LARGE', 'The request payload exceeds the supported limit.', 413);
        }

        $members = 0;
        $scalarBytes = 0;
        $stack = [[$request->all(), 0]];

        while ($stack !== []) {
            [$value, $depth] = array_pop($stack);
            if (is_array($value)) {
                if ($depth > $maxDepth) {
                    throw new ConnectorError('INVALID_REQUEST', 'The request payload structure is too deeply nested.');
                }

                foreach ($value as $key => $child) {
                    if (++$members > $maxMembers) {
                        throw new ConnectorError('INVALID_REQUEST', 'The request payload contains too many fields.');
                    }
                    $scalarBytes += is_string($key) ? strlen($key) : 0;
                    if ($scalarBytes > $maxScalarBytes) {
                        throw new ConnectorError('PAYLOAD_TOO_LARGE', 'The request payload exceeds the supported limit.', 413);
                    }
                    $stack[] = [$child, $depth + 1];
                }

                continue;
            }

            if (is_string($value)) {
                $scalarBytes += strlen($value);
                if ($scalarBytes > $maxScalarBytes) {
                    throw new ConnectorError('PAYLOAD_TOO_LARGE', 'The request payload exceeds the supported limit.', 413);
                }
            }
        }
    }
}
