<?php

namespace App\Tax\Vat;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ViesSoapProvider implements VatNumberProvider
{
    public function validate(string $countryCode, string $number): VatProviderResult
    {
        $attempts = max(1, min(3, (int) config('tax.vies.retries', 1) + 1));
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::connectTimeout(max(1, min(5, (int) config('tax.vies.connect_timeout', 2))))
                    ->timeout(max(1, min(10, (int) config('tax.vies.response_timeout', 4))))
                    ->withHeaders(['SOAPAction' => ''])
                    ->withBody($this->envelope($countryCode, $number), 'text/xml; charset=utf-8')
                    ->post((string) config('tax.vies.endpoint'));

                if (($response->serverError() || $response->status() === 429) && $attempt < $attempts) {
                    usleep($this->backoffMicroseconds($attempt));

                    continue;
                }

                if (! $response->successful()) {
                    throw new VatProviderUnavailable('VIES returned an unavailable response.');
                }

                return $this->parse($response->body());
            } catch (ConnectionException $exception) {
                $lastError = $exception;
                if ($attempt < $attempts) {
                    usleep($this->backoffMicroseconds($attempt));

                    continue;
                }
            } catch (VatProviderUnavailable $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new VatProviderUnavailable('VIES response could not be read safely.', previous: $exception);
            }
        }

        throw new VatProviderUnavailable('VIES is temporarily unavailable.', previous: $lastError);
    }

    private function envelope(string $countryCode, string $number): string
    {
        $country = htmlspecialchars($countryCode, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $vat = htmlspecialchars($number, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
            .'<soap:Body><urn:checkVat><urn:countryCode>'.$country.'</urn:countryCode><urn:vatNumber>'.$vat.'</urn:vatNumber></urn:checkVat></soap:Body>'
            .'</soap:Envelope>';
    }

    private function parse(string $xml): VatProviderResult
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new VatProviderUnavailable('VIES returned an invalid response.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('v', 'urn:ec.europa.eu:taxud:vies:services:checkVat:types');

        if ($xpath->query('//soap:Fault')->length > 0) {
            // Provider faults are availability/processing states, not invalid IDs.
            throw new VatProviderUnavailable('VIES could not complete the validation.');
        }

        $valid = trim((string) $xpath->evaluate('string(//v:valid)'));
        if (! in_array($valid, ['true', 'false'], true)) {
            throw new VatProviderUnavailable('VIES response did not contain a validation result.');
        }

        return new VatProviderResult(
            valid: $valid === 'true',
            requestDate: $this->nullable($xpath->evaluate('string(//v:requestDate)'), 64),
            referenceId: $this->nullable($xpath->evaluate('string(//v:requestIdentifier)'), 160),
            name: $this->nullable($xpath->evaluate('string(//v:name)'), 2000),
            address: $this->nullable($xpath->evaluate('string(//v:address)'), 2000),
        );
    }

    private function nullable(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' || in_array($value, ['---', 'n/a'], true) ? null : mb_substr($value, 0, $maxLength);
    }

    private function backoffMicroseconds(int $attempt): int
    {
        $base = max(0, min(1000, (int) config('tax.vies.retry_delay_ms', 150)));

        return min(1_000_000, $base * (2 ** ($attempt - 1)) * 1000);
    }
}
