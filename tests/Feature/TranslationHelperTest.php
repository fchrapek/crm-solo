<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class TranslationHelperTest extends TestCase
{
    public function test_a_polish_flash_falls_back_to_the_plain_action_key_instead_of_printing_the_key(): void
    {
        app()->setLocale('pl');

        $this->assertSame('Klient zaktualizowano.', translate_with_gender('updated', 'Client'));
        $this->assertSame('Kontakt utworzono.', translate_with_gender('created', 'Contact'));
    }

    public function test_an_english_flash_names_the_item(): void
    {
        app()->setLocale('en');

        $this->assertSame('Client updated.', translate_with_gender('updated', 'Client'));
    }
}
