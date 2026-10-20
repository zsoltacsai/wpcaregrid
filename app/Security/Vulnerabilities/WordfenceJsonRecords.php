<?php

namespace App\Security\Vulnerabilities;

/** Reads a top-level JSON object one record at a time without decoding the feed as a whole. */
final class WordfenceJsonRecords
{
    /** @return \Generator<string, array<string, mixed>> */
    public static function fromFile(string $path, int $maximumRecordBytes = 2 * 1024 * 1024): \Generator
    {
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new WordfenceSyncFailure('feed_read_failure', 'The vulnerability feed could not be opened.');
        }

        try {
            $reader = new self($input, $maximumRecordBytes);
            $reader->skipWhitespace();
            $reader->expect('{');
            $reader->skipWhitespace();
            if ($reader->peek() === '}') {
                throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains no records.');
            }

            while (true) {
                $key = $reader->readJsonString();
                $reader->skipWhitespace();
                $reader->expect(':');
                $reader->skipWhitespace();
                $record = $reader->readJsonObject();

                try {
                    // Keep objects distinct from arrays. In particular, an affected_versions
                    // object can have numeric version keys that PHP would otherwise turn into
                    // integer array keys (and mistake for a JSON list).
                    $decoded = json_decode($record, false, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains invalid JSON.');
                }
                if (! $decoded instanceof \stdClass) {
                    throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains an invalid record.');
                }

                yield $key => get_object_vars($decoded);

                $reader->skipWhitespace();
                $next = $reader->readChar();
                if ($next === '}') {
                    $reader->skipWhitespace();
                    if ($reader->readChar() !== null) {
                        throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains trailing data.');
                    }

                    return;
                }
                if ($next !== ',') {
                    throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed has an unexpected structure.');
                }
                $reader->skipWhitespace();
            }
        } finally {
            fclose($input);
        }
    }

    /** @param resource $input */
    private function __construct(private $input, private readonly int $maximumRecordBytes)
    {
        if ($maximumRecordBytes < 128) {
            throw new WordfenceSyncFailure('invalid_size_configuration', 'The configured record limit is invalid.');
        }
    }

    private string $buffer = '';

    private int $offset = 0;

    private function peek(): ?string
    {
        $this->fill();

        return $this->offset < strlen($this->buffer) ? $this->buffer[$this->offset] : null;
    }

    private function readChar(): ?string
    {
        $this->fill();
        if ($this->offset >= strlen($this->buffer)) {
            return null;
        }

        return $this->buffer[$this->offset++];
    }

    private function fill(): void
    {
        if ($this->offset < strlen($this->buffer) || feof($this->input)) {
            return;
        }

        $this->buffer = fread($this->input, 65536) ?: '';
        $this->offset = 0;
    }

    private function skipWhitespace(): void
    {
        while (($char = $this->peek()) !== null && str_contains(" \t\r\n", $char)) {
            $this->offset++;
        }
    }

    private function expect(string $expected): void
    {
        if ($this->readChar() !== $expected) {
            throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed has an unexpected structure.');
        }
    }

    private function readJsonString(): string
    {
        if ($this->readChar() !== '"') {
            throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed has an unexpected structure.');
        }

        $token = '"';
        $escaped = false;
        while (($char = $this->readChar()) !== null) {
            $token .= $char;
            if (strlen($token) > 512) {
                throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains an invalid record identifier.');
            }
            if (! $escaped && $char === '"') {
                try {
                    $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains an invalid record identifier.');
                }
                if (! is_string($key) || $key === '') {
                    throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains an invalid record identifier.');
                }

                return $key;
            }
            $escaped = ! $escaped && $char === '\\';
        }

        throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed ended unexpectedly.');
    }

    private function readJsonObject(): string
    {
        if ($this->readChar() !== '{') {
            throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains an invalid record.');
        }

        $record = '{';
        $depth = 1;
        $inString = false;
        $escaped = false;
        while (($char = $this->readChar()) !== null) {
            $record .= $char;
            if (strlen($record) > $this->maximumRecordBytes) {
                throw new WordfenceSyncFailure('feed_record_size_limit', 'A vulnerability record exceeds the safe record-size limit.');
            }
            if ($inString) {
                if (! $escaped && $char === '"') {
                    $inString = false;
                }
                $escaped = ! $escaped && $char === '\\';

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $record;
            }
        }

        throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed ended inside a record.');
    }
}
