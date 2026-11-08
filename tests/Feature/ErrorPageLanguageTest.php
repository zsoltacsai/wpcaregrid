<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPageLanguageTest extends TestCase
{
    public function test_the_not_found_page_declares_the_language_it_is_written_in(): void
    {
        $expected = ['en' => 'Not Found', 'hu' => 'Az oldal nem található'];

        foreach ($expected as $locale => $text) {
            $html = $this->withCookie('caregrid_locale', $locale)
                ->get('/this-page-does-not-exist')
                ->assertNotFound()
                ->assertSee($text)
                ->getContent();

            $this->assertStringContainsString('<html lang="'.$locale.'">', $html, "404 page in {$locale} must declare lang=\"{$locale}\".");
        }
    }

    public function test_every_status_page_uses_the_same_locale_aware_layout(): void
    {
        $html = $this->withCookie('caregrid_locale', 'hu')->get('/hu/this-page-does-not-exist')->assertNotFound()->getContent();

        $this->assertStringContainsString('<html lang="hu">', $html);
        $this->assertStringNotContainsString('<html lang="en">', $html);
    }
}
