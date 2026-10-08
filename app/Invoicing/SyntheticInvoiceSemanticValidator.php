<?php

namespace App\Invoicing;

use App\Models\SzamlazzSyntheticTestRun;
use DOMDocument;
use DOMXPath;

/** Semantic checks for the immutable synthetic commercial snapshot and exact provider XML. */
final class SyntheticInvoiceSemanticValidator
{
    /** @return list<string> */
    public function validateRun(SzamlazzSyntheticTestRun $run): array
    {
        $snapshot = $run->commercial_snapshot;
        if (! is_array($snapshot)) {
            return ['snapshot_missing', 'incorrect_amount_snapshot'];
        }

        $errors = [];
        $line = SyntheticSzamlazzTestContract::line();
        $gross = SyntheticSzamlazzTestContract::grossMinorUnits();
        $snapshotLine = is_array($snapshot['line'] ?? null) ? $snapshot['line'] : [];
        $billingConfiguration = is_array($snapshot['billing_tax_configuration'] ?? null) ? $snapshot['billing_tax_configuration'] : [];
        $snapshotBuyer = is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [];

        if ($run->designation !== 'synthetic_test' || ($snapshot['designation'] ?? null) !== 'synthetic_test') {
            $errors[] = 'designation_mismatch';
        }
        if (! hash_equals($run->snapshot_sha256, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))) {
            $errors[] = 'snapshot_hash_mismatch';
        }
        if ($run->currency !== SyntheticSzamlazzTestContract::CURRENCY
            || ($snapshot['currency'] ?? null) !== SyntheticSzamlazzTestContract::CURRENCY) {
            $errors[] = 'currency_mismatch';
        }
        if ($run->amount !== $gross || ($snapshot['amount'] ?? null) !== $gross || $snapshotLine !== $line) {
            $errors[] = 'incorrect_amount_snapshot';
        }
        if (($snapshot['tax_code'] ?? SyntheticSzamlazzTestContract::TAX_CODE) !== SyntheticSzamlazzTestContract::TAX_CODE
            || ($snapshotLine['tax_code'] ?? null) !== SyntheticSzamlazzTestContract::TAX_CODE) {
            $errors[] = 'tax_code_mismatch';
        }
        if (($snapshot['seller'] ?? null) !== SyntheticSzamlazzTestContract::seller()
            || ($snapshot['buyer'] ?? null) !== SyntheticSzamlazzTestContract::buyer()) {
            $errors[] = 'synthetic_party_mismatch';
        }
        if (($snapshot['notify_customer'] ?? null) !== false
            || isset($snapshotBuyer['email'])
            || ($snapshot['tax_decision_kind'] ?? null) !== 'synthetic_provider_code_selection_not_a_tax_decision') {
            $errors[] = 'synthetic_policy_mismatch';
        }
        if (($billingConfiguration['invoice_prefix'] ?? null) !== SyntheticSzamlazzTestContract::INVOICE_PREFIX) {
            $errors[] = 'invoice_prefix_mismatch';
        }
        if (! $run->test_account_attested || ! $run->test_seller_attested || $run->credential_reference !== 'szamlazz_agent') {
            $errors[] = 'owner_attestation_missing';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public function validateRequest(NormalizedInvoiceRequest $request, array $snapshot): array
    {
        $errors = [];
        $line = SyntheticSzamlazzTestContract::line();
        $reference = $request->externalReference;
        $billingConfiguration = is_array($snapshot['billing_tax_configuration'] ?? null) ? $snapshot['billing_tax_configuration'] : [];

        if (! $request->syntheticSnapshot || $request->syntheticSnapshot !== $snapshot
            || ($snapshot['designation'] ?? null) !== 'synthetic_test') {
            $errors[] = 'snapshot_missing_or_mismatched';
        }
        if ($request->currency !== SyntheticSzamlazzTestContract::CURRENCY || ($snapshot['currency'] ?? null) !== SyntheticSzamlazzTestContract::CURRENCY) {
            $errors[] = 'currency_mismatch';
        }
        if ($request->amountMinor !== SyntheticSzamlazzTestContract::grossMinorUnits()
            || ($snapshot['amount'] ?? null) !== SyntheticSzamlazzTestContract::grossMinorUnits()
            || ($snapshot['line'] ?? null) !== $line) {
            $errors[] = 'incorrect_amount_snapshot';
        }
        if (! $request->taxDecision instanceof SyntheticTestTaxSelection
            || $request->taxDecision->line !== $line
            || $request->taxDecision->line['tax_code'] !== SyntheticSzamlazzTestContract::TAX_CODE
            || $request->taxDecision->language !== 'hu'
            || ($snapshot['tax_code'] ?? null) !== SyntheticSzamlazzTestContract::TAX_CODE) {
            $errors[] = 'tax_code_mismatch';
        }
        $testAccount = is_array($snapshot['test_account'] ?? null) ? $snapshot['test_account'] : [];
        if (($testAccount['credential_reference'] ?? null) !== 'szamlazz_agent'
            || ($testAccount['owner_attested_test_mode'] ?? null) !== true
            || ($testAccount['test_seller_profile_confirmed'] ?? null) !== true
            || ! is_int($testAccount['owner_attestor_id'] ?? null)
            || $testAccount['owner_attestor_id'] < 1
            || ! is_string($testAccount['owner_attested_at'] ?? null)
            || $testAccount['owner_attested_at'] === '') {
            $errors[] = 'owner_attestation_missing';
        }
        if ($request->seller !== SyntheticSzamlazzTestContract::seller()
            || $request->buyer !== SyntheticSzamlazzTestContract::buyer()
            || isset($request->buyer['email'])) {
            $errors[] = 'synthetic_party_mismatch';
        }
        if ($request->description !== 'WP CareGrid synthetic Szamlazz integration test'
            || ($request->billingSettings['notify_customer'] ?? null) !== false
            || ($snapshot['notify_customer'] ?? null) !== false
            || ($snapshot['tax_decision_kind'] ?? null) !== 'synthetic_provider_code_selection_not_a_tax_decision') {
            $errors[] = 'synthetic_policy_mismatch';
        }
        $prefix = $billingConfiguration['invoice_prefix'] ?? null;
        if ($prefix !== SyntheticSzamlazzTestContract::INVOICE_PREFIX
            || ($request->billingSettings['invoice_prefix'] ?? null) !== $prefix) {
            $errors[] = 'invoice_prefix_mismatch';
        }
        if (! preg_match('/\Awpcg-synthetic-test-[0-9]{1,12}\z/D', $reference)) {
            $errors[] = 'invalid_reference';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public function validateExactXml(NormalizedInvoiceRequest $request, array $snapshot, string $xml): array
    {
        $errors = $this->validateRequest($request, $snapshot);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return [...$errors, 'xml_malformed'];
            }
            $xsd = config('szamlazz.xsd_path');
            if (! is_string($xsd) || ! is_file($xsd) || ! $document->schemaValidate($xsd)) {
                $errors[] = 'xml_xsd_invalid';
            }

            $xpath = new DOMXPath($document);
            $expected = [
                'penznem' => SyntheticSzamlazzTestContract::CURRENCY,
                'szamlaszamElotag' => SyntheticSzamlazzTestContract::INVOICE_PREFIX,
                'szamlaLetoltes' => 'true',
                'szamlaKulsoAzon' => $request->externalReference,
                'rendelesSzam' => $request->externalReference,
                'nev' => SyntheticSzamlazzTestContract::buyer()['name'],
                'orszag' => SyntheticSzamlazzTestContract::buyer()['country'],
                'irsz' => SyntheticSzamlazzTestContract::buyer()['postal_code'],
                'telepules' => SyntheticSzamlazzTestContract::buyer()['city'],
                'cim' => SyntheticSzamlazzTestContract::buyer()['address'],
                'sendEmail' => 'false',
                'adoszam' => '',
                'adoszamEU' => '',
                'nettoEgysegar' => SyntheticSzamlazzTestContract::formatMinorUnits(SyntheticSzamlazzTestContract::grossMinorUnits()),
                'afakulcs' => SyntheticSzamlazzTestContract::TAX_CODE,
                'nettoErtek' => SyntheticSzamlazzTestContract::formatMinorUnits(SyntheticSzamlazzTestContract::grossMinorUnits()),
                'afaErtek' => SyntheticSzamlazzTestContract::formatMinorUnits(0),
                'bruttoErtek' => SyntheticSzamlazzTestContract::formatMinorUnits(SyntheticSzamlazzTestContract::grossMinorUnits()),
            ];
            foreach ($expected as $name => $value) {
                $node = $xpath->query('//*[local-name()="'.$name.'"]')->item(0);
                if (! $node || $node->textContent !== $value) {
                    $errors[] = $name.'_mismatch';
                }
            }
            $keyNode = $xpath->query('//*[local-name()="szamlaagentkulcs"]')->item(0);
            if (! $keyNode || trim($keyNode->textContent) === '') {
                $errors[] = 'provider_credential_missing';
            }
            if ($xpath->query('//*[local-name()="szamlaszam"]')->length > 0) {
                $errors[] = 'local_invoice_number_forbidden';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return array_values(array_unique($errors));
    }
}
