<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Inlines the approved brand SVGs from design/brand so the page never uses a
 * redrawn copy of the logo. Inlining (rather than <img>) lets the wordmark
 * use the self-hosted IBM Plex Sans instead of a fallback font.
 *
 * The only permitted changes are the ones the brand guidelines allow:
 * dropping the optional descriptor at small sizes and trimming empty space
 * from the viewBox. Shapes, colours and proportions are left untouched.
 */
final class BrandAsset
{
    /**
     * The horizontal logos use a 420×72 canvas but the artwork ends at about
     * x=279 (x=273 without the descriptor), measured with IBM Plex Sans
     * loaded. Trimming the empty right edge keeps layout widths honest.
     * Without the descriptor the height keeps the grid's 9px top margin.
     */
    private const HORIZONTAL_VIEWBOX = ['0 0 280 68', '0 0 284 72'];

    /** @var array<string, string> */
    private static array $cache = [];

    public static function logo(string $layout = 'horizontal', string $tone = 'light', bool $descriptor = true): string
    {
        if (! in_array($layout, ['horizontal', 'stacked'], true) || ! in_array($tone, ['light', 'dark'], true)) {
            throw new InvalidArgumentException("Unknown logo variant [{$layout}-{$tone}].");
        }

        return self::$cache["logo-{$layout}-{$tone}-".(int) $descriptor] ??= self::prepare(
            self::read("logo/wpcaregrid-{$layout}-{$tone}.svg"),
            descriptor: $descriptor,
            viewBox: $layout === 'horizontal' ? self::HORIZONTAL_VIEWBOX[(int) $descriptor] : null,
        );
    }

    public static function icon(): string
    {
        return self::$cache['icon'] ??= self::prepare(self::read('icons/wpcaregrid-favicon.svg'));
    }

    private static function read(string $path): string
    {
        return file_get_contents(base_path('design/brand/'.$path));
    }

    private static function prepare(string $svg, bool $descriptor = true, ?string $viewBox = null): string
    {
        // Sizing is controlled with CSS; accessible naming by the caller.
        $svg = preg_replace('/<svg([^>]*?)\s+width="[^"]*"\s+height="[^"]*"/', '<svg$1', $svg, 1);
        $svg = preg_replace('/\s+role="img"\s+aria-label="[^"]*"/', ' aria-hidden="true" focusable="false"', $svg, 1);

        if (! $descriptor) {
            $svg = preg_replace('/<text[^>]*>WORDPRESS MAINTENANCE<\/text>\s*/', '', $svg);
        }

        if ($viewBox) {
            $svg = preg_replace('/viewBox="[^"]*"/', 'viewBox="'.$viewBox.'"', $svg, 1);
        }

        return trim($svg);
    }
}
