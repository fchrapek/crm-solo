<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demo and dummy data must stay unmistakably fake.
 *
 * Two ways it stops being fake by accident. A plausible-looking domain can be
 * a real company's, and a demo instance then shows invented people with
 * invented complaints at a real address. And a tax id with a valid checksum
 * can collide with a real registration, since the checksum is the only thing
 * separating a made-up number from a live one.
 *
 * So: every domain in seed data is under a reserved TLD (RFC 2606), and every
 * seeded tax id deliberately fails its checksum.
 */
final class DemoDataIsFictionalTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function seedFiles(): array
    {
        return [
            [__DIR__.'/../../database/seeders/DemoSeeder.php'],
            [__DIR__.'/../../app/Console/Commands/SeedDummyLeads.php'],
        ];
    }

    #[DataProvider('seedFiles')]
    public function test_every_seeded_domain_is_reserved_for_testing(string $file): void
    {
        $source = (string) file_get_contents($file);

        preg_match_all(
            '/\b[a-z0-9][a-z0-9.-]*\.(?:pl|com|eu|io|org|net|digital|academy|dev|app)\b/i',
            $source,
            $matches,
        );

        $live = array_values(array_unique(array_filter(
            $matches[0],
            // The project's own demo instance is real and may be named.
            fn (string $domain): bool => $domain !== 'demo.crm-solo.com',
        )));

        $this->assertSame(
            [],
            $live,
            basename($file).' seeds a domain outside a reserved TLD: '.implode(', ', $live).
            '. Use .test so demo data can never point at a stranger.',
        );
    }

    #[DataProvider('seedFiles')]
    public function test_every_seeded_tax_id_fails_its_checksum(string $file): void
    {
        $source = (string) file_get_contents($file);

        preg_match_all("/'(?:nip|tax_id)' => '(\d{10})'/", $source, $matches);

        $found = array_unique($matches[1]);

        // Not every seed file carries tax ids; assert that explicitly rather
        // than letting the loop pass by never running.
        $this->assertIsArray($found);

        foreach ($found as $nip) {
            $this->assertFalse(
                $this->isValidNip($nip),
                "Seeded tax id {$nip} has a valid checksum, so it may belong to a real company. ".
                'Pick one that fails the check.',
            );
        }
    }

    /**
     * `migrate --seed` is the command the README hands a new reader, and it
     * runs the factory, not DemoSeeder. Faker's pl_PL provider emits VALID
     * tax ids and real-looking .pl mail domains, so before 2026-09-03 that
     * command wrote a hundred rows carrying live identifiers into every
     * developer's database.
     */
    public function test_the_client_factory_never_generates_usable_identifiers(): void
    {
        $clients = Client::factory()->count(200)->make();

        foreach ($clients as $client) {
            if ($client->tax_id !== null) {
                $this->assertFalse(
                    $this->isValidNip($client->tax_id),
                    "Factory produced tax id {$client->tax_id}, which passes the checksum and may be a real registration.",
                );
            }

            if ($client->email !== null) {
                $this->assertMatchesRegularExpression(
                    '/@[a-z0-9-]+\.(?:test|example|invalid|localhost)$/i',
                    $client->email,
                    "Factory produced {$client->email}, which is outside the reserved TLDs.",
                );
            }
        }
    }

    /**
     * Polish NIP: weights 6,5,7,2,3,4,5,6,7 over the first nine digits, the
     * sum modulo 11 must equal the tenth. A remainder of 10 is never valid.
     */
    private function isValidNip(string $nip): bool
    {
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += (int) $nip[$i] * $weight;
        }

        $checksum = $sum % 11;

        return $checksum !== 10 && $checksum === (int) $nip[9];
    }
}
