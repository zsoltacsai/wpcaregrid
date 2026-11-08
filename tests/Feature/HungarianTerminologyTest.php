<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Owner-approved Hungarian customer-facing terminology (docs/CONTENT_TERMINOLOGY.md).
 * Scans string values only, so array keys, routes and other identifiers are untouched.
 */
class HungarianTerminologyTest extends TestCase
{
    private const CUSTOMER_FACING = ['home', 'pricing', 'dashboard', 'account', 'backups', 'privacy', 'checkout'];

    /** @return list<string> */
    private function values(string $file): array
    {
        $values = [];
        $strings = require lang_path("hu/{$file}.php");
        array_walk_recursive($strings, function ($value) use (&$values): void {
            if (is_string($value)) {
                $values[] = $value;
            }
        });

        return $values;
    }

    public function test_customer_facing_hungarian_text_avoids_the_retired_terms(): void
    {
        foreach (self::CUSTOMER_FACING as $file) {
            foreach ($this->values($file) as $value) {
                $text = mb_strtolower($value);
                foreach (['honlap', 'vezérlőpult', 'ügyfélfelület'] as $retired) {
                    $this->assertStringNotContainsString($retired, $text, "hu/{$file}.php uses '{$retired}': {$value}");
                }
                $this->assertDoesNotMatchRegularExpression('/\bbackup(ok|ot|ja)?\b/u', $text, "hu/{$file}.php uses the English word backup: {$value}");
            }
        }
    }

    public function test_the_dashboard_is_called_irányítópult_and_the_plan_a_karbantartási_csomag(): void
    {
        $account = (require lang_path('hu/account.php'));
        $this->assertSame('Irányítópult', $account['nav']['dashboard']);
        $this->assertSame('Irányítópult', $account['dashboard']['title']);
        $this->assertStringContainsString('karbantartási csomag', $account['dashboard']['no_subscription']);
        $this->assertStringContainsString('karbantartási csomag', $account['websites']['no_access']);
    }
}
