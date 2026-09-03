<?php

declare(strict_types=1);

namespace Database\Factories;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mirrors the shape of real Infakt-synced clients: Polish businesses,
 * `Sp. z o.o.` / sole-prop names, PL addresses, PLN currency, mostly missing
 * phones (Infakt rarely captures them).
 *
 * Identifiers are deliberately unusable: tax ids fail their checksum, mail is
 * on a reserved TLD and phones sit in an unassigned range. This runs on a
 * developer's first `migrate --seed`, so it must not fabricate a hundred
 * records that point at real companies.
 */
final class ClientFactory extends Factory
{
    private static ?Generator $pl = null;

    public function definition(): array
    {
        $pl = self::pl();

        $nameShape = $pl->randomElement([
            'corporate', 'corporate', 'corporate',  // Sp. z o.o. dominate
            'sole_prop',                            // "Imię Nazwisko Nazwa Działalności"
            'sole_prop',
            'individual',                           // bare person name
        ]);

        return [
            'type' => 'business',
            'name' => match ($nameShape) {
                'corporate' => $pl->company(),
                'sole_prop' => mb_strtoupper($pl->firstName().' '.$pl->lastName()).' '.$pl->company(),
                default => $pl->firstName().' '.$pl->lastName(),
            },
            'email' => $pl->boolean(36) ? $pl->userName().'@'.$pl->domainWord().'.test' : null,
            'phone' => $pl->boolean(2) ? '+48 100 '.$pl->numerify('### ###') : null,
            'address' => $pl->streetAddress(),
            'city' => $pl->city(),
            'region' => null,
            'country' => 'PL',
            'postal_code' => $pl->postcode(),
            'tax_id' => $pl->boolean(92) ? self::invalidNip($pl) : null,
            'business_type' => null,
            'notes' => $pl->optional(0.02)->sentence(),
            'currency' => 'PLN',
            'lifecycle_stage' => 'active',
        ];
    }

    /**
     * Override to a US-style business client (for tests that explicitly need
     * non-PL data).
     */
    public function unitedStates(): self
    {
        return $this->state(fn () => [
            'country' => 'US',
            'currency' => 'USD',
            'name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'region' => fake()->state(),
            'postal_code' => fake()->postcode(),
            'tax_id' => fake()->numerify('##-#######'),
            'email' => fake()->safeEmail(),
        ]);
    }

    /**
     * Override to an individual person rather than a business.
     */
    public function individual(): self
    {
        $pl = self::pl();

        return $this->state(fn () => [
            'type' => 'individual',
            'name' => $pl->firstName().' '.$pl->lastName(),
            'tax_id' => null,
            'business_type' => null,
        ]);
    }

    /**
     * A NIP-shaped number whose checksum is deliberately wrong.
     *
     * Faker's taxpayerIdentificationNumber() emits valid ones, and a valid NIP
     * can belong to a real company, so seeding one puts a stranger's tax id in
     * a developer's database. Shifting the check digit off the correct value
     * keeps the format without ever landing on a live registration. Pinned by
     * DemoDataIsFictionalTest.
     */
    private static function invalidNip(Generator $pl): string
    {
        $digits = $pl->numerify('#########');
        $sum = 0;

        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        return $digits.(($sum % 11 + 1) % 10);
    }

    private static function pl(): Generator
    {
        return self::$pl ??= FakerFactory::create('pl_PL');
    }
}
