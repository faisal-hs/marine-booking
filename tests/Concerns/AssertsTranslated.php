<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * A missing translation key does not fail a page: Laravel prints the key itself
 * ("admin.providers.filter") where the words should be, in a language the developer may
 * never open. This asserts that no such raw key is visible on a page, and is run in both
 * English and Arabic for every admin screen the dashboard modules add.
 */
trait AssertsTranslated
{
    protected function assertNoRawTranslationKeys(TestResponse $response, string $context = ''): void
    {
        // Only text a person reads: drop tags (attributes, URLs, class names) and scripts.
        $text = preg_replace(['#<script.*?</script>#si', '#<style.*?</style>#si', '#<[^>]+>#'], ' ', $response->getContent());

        preg_match_all('/\badmin\.[a-z_]+(?:\.[a-z_0-9]+)+\b/', (string) $text, $found);

        $this->assertSame([], array_values(array_unique($found[0])), trim("Untranslated keys are showing on the page {$context}"));
    }

    /** Runs $visit once per admin language, so Arabic is checked as seriously as English. */
    protected function inEachAdminLanguage(callable $visit): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->withSession(['admin_locale' => $locale]);
            $visit($locale);
        }
    }
}
