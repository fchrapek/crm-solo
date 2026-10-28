<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LegalName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalNameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function legalForms(): array
    {
        return [
            'sp. z o.o.' => ['PRACOWNIA TESTOWA Sp. z o.o.', 'PRACOWNIA TESTOWA'],
            'sp. z o. o. with spaces' => ['Studio Przykładowe sp. z o. o.', 'Studio Przykładowe'],
            'sp z oo without dots' => ['Acme sp z oo', 'Acme'],
            'spelled out limited company' => ['Acme Spółka z ograniczoną odpowiedzialnością', 'Acme'],
            'sp.k.' => ['Warsztat Próbny sp.k.', 'Warsztat Próbny'],
            'sp. k.' => ['Warsztat Próbny Sp. k.', 'Warsztat Próbny'],
            'spółka komandytowa' => ['Warsztat Próbny spółka komandytowa', 'Warsztat Próbny'],
            'limited and limited partnership together' => ['Agencja Wzorcowa Sp. z o.o. Sp.k.', 'Agencja Wzorcowa'],
            'two forms with a comma between them' => ['Agencja Wzorcowa Sp. z o.o., Sp.k.', 'Agencja Wzorcowa'],
            'S.A.' => ['Hurtownia Przykład S.A.', 'Hurtownia Przykład'],
            'SA in capitals' => ['Hurtownia Przykład SA', 'Hurtownia Przykład'],
            'spółka akcyjna' => ['Hurtownia Przykład Spółka Akcyjna', 'Hurtownia Przykład'],
            's.c.' => ['Eko-Serwis Testowy s.c.', 'Eko-Serwis Testowy'],
            'S.C. in capitals' => ['Eko-Serwis Testowy S.C.', 'Eko-Serwis Testowy'],
            'spółka cywilna' => ['Eko-Serwis Testowy spółka cywilna', 'Eko-Serwis Testowy'],
            'sp.j.' => ['Sadownia Przykładowa sp.j.', 'Sadownia Przykładowa'],
            'spółka jawna' => ['Sadownia Przykładowa Spółka Jawna', 'Sadownia Przykładowa'],
            'sp.p.' => ['Kancelaria Testowa sp.p.', 'Kancelaria Testowa'],
            'spółka partnerska' => ['Kancelaria Testowa spółka partnerska', 'Kancelaria Testowa'],
            'S.K.A.' => ['Siłownia Próbna Sp. z o.o. S.K.A.', 'Siłownia Próbna'],
            'P.S.A.' => ['Studio-X Przykład P.S.A.', 'Studio-X Przykład'],
            'prosta spółka akcyjna' => ['Studio-X Przykład prosta spółka akcyjna', 'Studio-X Przykład'],
            'comma before the form' => ['Pracownia Wzorcowa, sp. z o.o.', 'Pracownia Wzorcowa'],
            'surrounding whitespace' => ['  Pracownia Wzorcowa   sp. z o.o.  ', 'Pracownia Wzorcowa'],
            'foreign GmbH' => ['Stoiska Testowe GmbH', 'Stoiska Testowe'],
            'foreign Ltd.' => ['Stoiska Testowe Ltd.', 'Stoiska Testowe'],
            'foreign AG in capitals' => ['Stoiska Testowe AG', 'Stoiska Testowe'],
            'sp. zo.o. spaced oddly' => ['Pracownia Wzorcowa Sp.zo.o', 'Pracownia Wzorcowa'],
            'spółka z o.o.' => ['Pracownia Wzorcowa Spółka z o.o.', 'Pracownia Wzorcowa'],
            'spółka komandytowo-akcyjna' => ['Siłownia Próbna spółka komandytowo-akcyjna', 'Siłownia Próbna'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function namesWithoutAForm(): array
    {
        return [
            'a person' => ['Jan Przykładowy Doradztwo'],
            'brackets' => ['Anna Testowa (Studio Próbne)'],
            'a word ending in the letters sa' => ['Casa'],
            'a last word Sa in mixed case' => ['Mesa Sa'],
            'a last word Ag in mixed case' => ['Studio Ag'],
            'a form in brackets' => ['Pracownia Testowa (SA)'],
            'a form joined by a hyphen' => ['Pracownia-Ltd'],
            'and Co, which only the duplicate check looks past' => ['Smith & Co'],
            'a trailing hyphen with no form before it' => ['Studio-'],
            'a trailing comma with no form before it' => ['Pracownia Testowa,'],
            'a form inside the name' => ['Spółka Jawna Kowalski i Syn Catering'],
        ];
    }

    #[DataProvider('legalForms')]
    public function test_the_legal_form_is_stripped_from_the_end(string $name, string $short): void
    {
        $this->assertSame($short, LegalName::short($name));
    }

    #[DataProvider('namesWithoutAForm')]
    public function test_a_name_without_a_trailing_legal_form_is_kept(string $name): void
    {
        $this->assertSame($name, LegalName::short($name));
    }

    public function test_a_name_that_is_only_a_legal_form_is_never_emptied(): void
    {
        $this->assertSame('Sp. z o.o.', LegalName::short('Sp. z o.o.'));
        $this->assertSame('S.A.', LegalName::short(' S.A. '));
    }
}
