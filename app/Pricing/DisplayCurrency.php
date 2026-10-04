<?php

namespace App\Pricing;

/**
 * Which currency the public pricing is displayed in. Presentation only:
 *
 * - The server never charges what the browser displays. Checkout resolves
 *   billing country → market → price on the server (StartCheckout), and the
 *   Stripe Price ID always comes from the catalogue. Nothing here is read by
 *   checkout, and no currency, amount or Price ID from a request is trusted.
 * - Only currencies that have real prices are offered (PricingCatalog::
 *   sellableCurrencies()). Today that is HUF only. There is no conversion,
 *   no exchange rate and no IP-based choice; a market without prices offers
 *   nothing until its prices exist.
 * - Hungarian is always HUF. Choosing English returns to the default
 *   currency of the display market.
 */
final class DisplayCurrency
{
    private const SESSION_KEY = 'display_currency';

    /**
     * Currencies the visitor can choose between in this language.
     *
     * @return list<string>
     */
    public function available(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return $locale === 'hu' ? ['HUF'] : $this->catalog()->sellableCurrencies();
    }

    /** More than one real choice: only then is a selector shown. */
    public function canChoose(): bool
    {
        return count($this->available()) > 1;
    }

    /** The currency prices are shown in. */
    public function selected(): string
    {
        $available = $this->available();
        $chosen = session(self::SESSION_KEY);

        if (is_string($chosen) && in_array($chosen, $available, true)) {
            return $chosen;
        }

        $default = $this->catalog()->market($this->catalog()->displayMarket)->currencyCode;

        return in_array($default, $available, true) ? $default : ($available[0] ?? $default);
    }

    /** The market whose price list is displayed in the selected currency. */
    public function market(): Market
    {
        return $this->catalog()->marketForCurrency($this->selected())
            ?? $this->catalog()->market($this->catalog()->displayMarket);
    }

    /** @return bool False when the currency is not one of the available ones (nothing changes). */
    public function select(string $code): bool
    {
        if (! in_array($code, $this->available(), true)) {
            return false;
        }

        session([self::SESSION_KEY => $code]);

        return true;
    }

    /** Back to the language's default (a language change). */
    public function reset(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private function catalog(): PricingCatalog
    {
        return app(PricingCatalog::class);
    }
}
