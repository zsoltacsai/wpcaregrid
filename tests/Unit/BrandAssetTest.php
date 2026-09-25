<?php

namespace Tests\Unit;

use App\Support\BrandAsset;
use Tests\TestCase;

class BrandAssetTest extends TestCase
{
    public function test_logo_is_the_supplied_artwork(): void
    {
        $original = file_get_contents(base_path('design/brand/logo/wpcaregrid-horizontal-light.svg'));
        $inlined = BrandAsset::logo('horizontal', 'light');

        // Every shape of the approved logo is kept unchanged.
        preg_match_all('/<rect x=[^>]+>/', $original, $shapes);
        $this->assertCount(9, $shapes[0]);
        foreach ($shapes[0] as $shape) {
            $this->assertStringContainsString($shape, $inlined);
        }

        $this->assertStringContainsString('WORDPRESS MAINTENANCE', $inlined);
        $this->assertStringContainsString('aria-hidden="true"', $inlined);
    }

    public function test_descriptor_can_be_omitted_at_small_sizes(): void
    {
        $logo = BrandAsset::logo('horizontal', 'light', descriptor: false);

        $this->assertStringNotContainsString('WORDPRESS MAINTENANCE', $logo);
        $this->assertStringContainsString('>CareGrid</text>', $logo);
    }

    public function test_dark_variant_and_icon_load(): void
    {
        $this->assertStringContainsString('fill="#17212B"', BrandAsset::logo('horizontal', 'dark'));
        $this->assertStringContainsString('fill="#5B8C72"', BrandAsset::icon());
    }
}
