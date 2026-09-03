<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\I18NextTranslationsLoader;
use Tests\TestCase;

/**
 * Polish breadcrumbs rendered the English "Clients" until 2026-09-03: the
 * loader emitted only _one and _other, and i18next asks Polish for _few at a
 * count of 2, so it fell through to the fallback language.
 */
final class I18NextTranslationsLoaderTest extends TestCase
{
    public function test_two_form_pluralisation_covers_every_polish_category(): void
    {
        $translations = $this->load('pl');

        $this->assertSame('Klient', $translations['Client_one']);
        $this->assertSame('Klienci', $translations['Client_few']);
        $this->assertSame('Klienci', $translations['Client_many']);
        $this->assertSame('Klienci', $translations['Client_other']);
    }

    public function test_three_form_pluralisation_keeps_the_form_that_was_dropped(): void
    {
        $translations = $this->load('pl');

        $this->assertSame('Jest {{count}} błąd w formularzu.', $translations['form_errors_one']);
        $this->assertSame('Są {{count}} błędy w formularzu.', $translations['form_errors_few']);
        $this->assertSame('Jest {{count}} błędów w formularzu.', $translations['form_errors_many']);
    }

    public function test_english_keeps_its_singular_and_plural(): void
    {
        $translations = $this->load('en');

        $this->assertSame('Client', $translations['Client_one']);
        $this->assertSame('Clients', $translations['Client_other']);
    }

    /**
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        return app(I18NextTranslationsLoader::class)->loadTranslations($locale);
    }
}
