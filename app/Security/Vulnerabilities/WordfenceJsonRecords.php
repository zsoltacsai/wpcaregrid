<?php

namespace App\Security\Vulnerabilities;

use RuntimeException;

/** Reads a top-level JSON object one record at a time without decoding the feed as a whole. */
final class WordfenceJsonRecords
{
    /** @return \Generator<string, array<string, mixed>> */
    public static function fromFile(string $path, int $maximumRecordBytes = 2 * 1024 * 1024): \Generator
    {
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('The vulnerability feed could not be opened.');
        }

        try {
            $reader = new self($input, $maximumRecordBytes);
            $reader->skipWhitespace();
            $reader->expect('{');
            $reader->skipWhitespace();
            if ($reader->peek() === '}') {
                throw new RuntimeException('The vulnerability feed contains no records.');
            }

            while (true) {
                $key = $reader->readJsonString();
                $reader->skipWhitespace();
                $reader->expect(':');
                $reader->skipWhitespace();
                $record = $reader->readJsonObject();

                try {
                    $decoded = json_decode($record, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new RuntimeException('The vulnerability feed contains invalid JSON.');
                }
                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new RuntimeException('The vulnerability feed contains an invalid record.');
                }

                yield $key => $decoded;

                $reader->skipWhitespace();
                $next = $reader->readChar();
                if ($next === '}') {
                    $reader->skipWhitespace();
                    if ($reader->readChar() !== null) {
                        throw new RuntimeException('The vulnerability feed contains trailing data.');
                    }

                    return;
                }
                if ($next !== ',') {
                    throw new RuntimeException('The vulnerability feed has an unexpected structure.');
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
            throw new RuntimeException('The configured record limit is invalid.');
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
            throw new RuntimeException('The vulnerability feed has an unexpected structure.');
        }
    }

    private function readJsonString(): string
    {
        if ($this->readChar() !== '"') {
            throw new RuntimeException('The vulnerability feed has an unexpected structure.');
        }

        $token = '"';
        $escaped = false;
        while (($char = $this->readChar()) !== null) {
            $token .= $char;
            if (strlen($token) > 512) {
                throw new RuntimeException('The vulnerability feed contains an invalid record identifier.');
            }
            if (! $escaped && $char === '"') {
                try {
                    $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new RuntimeException('The vulnerability feed contains an invalid record identifier.');
                }
                if (! is_string($key) || $key === '') {
                    throw new RuntimeException('The vulnerability feed contains an invalid record identifier.');
                }

                return $key;
            }
            $escaped = ! $escaped && $char === '\\';
        }

        throw new RuntimeException('The vulnerability feed ended unexpectedly.');
    }

    private function readJsonObject(): string
    {
        if ($this->readChar() !== '{') {
            throw new RuntimeException('The vulnerability feed contains an invalid record.');
        }

        $record = '{';
        $depth = 1;
        $inString = false;
        $escaped = false;
        while (($char = $this->readChar()) !== null) {
            $record .= $char;
            if (strlen($record) > $this->maximumRecordBytes) {
                throw new RuntimeException('A vulnerability record exceeds the safe record-size limit.');
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

        throw new RuntimeException('The vulnerability feed ended inside a record.');
    }
}
