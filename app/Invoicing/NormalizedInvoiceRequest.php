<?php

namespace App\Invoicing;

/** Immutable, provider-neutral data prepared from confirmed billing and frozen identities. */
final readonly class NormalizedInvoiceRequest
{
    /** @param array<string, string> $seller @param array<string, string> $buyer */
    public function __construct(
        public string $externalReference,
        public string $currency,
        public int $amountMinor,
        public string $issueDate,
        public string $performanceDate,
        public string $paymentDueDate,
        public ?string $paymentDate,
        public string $paymentMethod,
        public array $seller,
        public array $buyer,
        public string $description,
        public ApprovedTaxDecision|SyntheticTestTaxSelection $taxDecision,
        public array $billingSettings = [],
        public ?array $syntheticSnapshot = null,
    ) {}

    public function withBillingSettings(array $settings, ?string $externalReference = null): self
    {
        if (! $this->taxDecision instanceof ApprovedTaxDecision) {
            throw new \LogicException('Synthetic tax selections cannot be converted into approved invoice decisions.');
        }

        $language = is_string($settings['invoice_language'] ?? null) ? $settings['invoice_language'] : $this->taxDecision->language;
        $paymentMethod = is_string($settings['default_payment_method'] ?? null)
            ? (SzamlazzPaymentMethodRegistry::label($settings['default_payment_method'], $language) ?? $this->paymentMethod)
            : $this->paymentMethod;
        $decision = new ApprovedTaxDecision(
            $this->taxDecision->reference,
            $this->taxDecision->version,
            $this->taxDecision->line,
            $language,
            $this->taxDecision->exchangeRate,
            $this->taxDecision->exchangeRateBank,
        );
        $dueDays = match ($settings['payment_deadline_policy'] ?? null) {
            'days_8' => 8, 'days_15' => 15, 'days_30' => 30, default => 0,
        };
        $due = (new \DateTimeImmutable($this->issueDate))->modify('+'.$dueDays.' days')->format('Y-m-d');
        $performance = ($settings['fulfillment_date_policy'] ?? null) === 'invoice_date'
            ? $this->issueDate
            : ($this->paymentDate ?? $this->performanceDate);

        return new self(
            $externalReference ?? $this->externalReference,
            $this->currency,
            $this->amountMinor,
            $this->issueDate,
            $performance,
            $due,
            $this->paymentDate,
            $paymentMethod,
            $this->seller,
            $this->buyer,
            $this->description,
            $decision,
            $settings,
            null,
        );
    }
}
