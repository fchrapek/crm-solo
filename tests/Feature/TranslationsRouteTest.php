<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one route an anonymous caller can reach, because the frontend fetches
 * strings before a session exists. Its locale lands in a filesystem path, and
 * ".." is a single valid URL segment, which walked the loader into the project
 * root until 2026-09-03.
 */
final class TranslationsRouteTest extends TestCase
{
    public function test_it_serves_a_known_locale(): void
    {
        $this->get('/locales/en/translation.json')->assertOk();
        $this->get('/locales/pl/translation.json')->assertOk();
    }

    public function test_it_refuses_a_locale_that_escapes_the_lang_directory(): void
    {
        foreach (['%2e%2e', '..', '%2e%2e%2f%2e%2e', 'en/../..'] as $attempt) {
            $this->get("/locales/{$attempt}/translation.json")
                ->assertNotFound();
        }
    }

    public function test_it_refuses_a_locale_that_is_not_a_language_tag(): void
    {
        $this->get('/locales/config/translation.json')->assertNotFound();
        $this->get('/locales/vendor/translation.json')->assertNotFound();
    }
}
