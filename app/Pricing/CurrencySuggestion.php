<?php

namespace App\Pricing;

use Illuminate\Http\Request;

/** Browser locale is an initial display hint only; billing country decides checkout market. */
final class CurrencySuggestion
{
    public static function forLocale(string $locale): string
    {
        $parts = explode('-', str_replace('_', '-', trim($locale)));
        $language = strtolower($parts[0] ?? '');
        $region = strtoupper($parts[1] ?? '');

        if ($language === 'hu') {
            return 'HUF';
        }
        if ($language === 'en' && $region === 'US') {
            return 'USD';
        }
        if ($language === 'en' && $region === 'GB') {
            return 'GBP';
        }

        $euroRegions = ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'];
        $euroLanguages = ['bg', 'cs', 'da', 'el', 'et', 'fi', 'fr', 'ga', 'hr', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'];

        if (in_array($region, $euroRegions, true) || in_array($language, $euroLanguages, true)) {
            return 'EUR';
        }

        return 'EUR';
    }

    public static function fromRequest(?Request $request = null): string
    {
        $request ??= request();
        $languages = $request->getLanguages();

        return self::forLocale((string) ($languages[0] ?? app()->getLocale()));
    }
}
