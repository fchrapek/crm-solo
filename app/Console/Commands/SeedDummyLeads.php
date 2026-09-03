<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Lead;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Throwaway lead cards for evaluating the candidate looks against a full
 * board (the demo look-and-feel notes).
 *
 * This writes into the REAL local database, so every row is stamped with
 * external_ref = self::MARKER and `--remove` deletes exactly those rows and
 * nothing else. Delete this command once a look is chosen.
 *
 *   php artisan leads:seed-dummy
 *   php artisan leads:seed-dummy --remove
 */
#[AsCommand(name: 'leads:seed-dummy', description: 'Seed (or remove) throwaway lead cards for UI evaluation')]
final class SeedDummyLeads extends Command
{
    /**
     * Every dummy row gets external_ref = MARKER . '-' . n, so removal can
     * match the prefix exactly. It cannot be one shared value: leads carry a
     * unique index on (account_id, external_ref).
     */
    private const MARKER = 'dummy-look-test';

    protected $signature = 'leads:seed-dummy {--remove : Delete the dummy leads instead of creating them}';

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        $account = Account::query()->first();
        if (! $account) {
            $this->error('No account found.');

            return self::FAILURE;
        }

        $existing = Lead::withTrashed()->where('external_ref', 'like', self::MARKER.'%')->count();
        if ($existing > 0) {
            $this->warn("{$existing} dummy leads already exist. Run with --remove first to reseed.");

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($this->rows() as $i => $row) {
            $lead = Lead::create([
                'account_id' => $account->id,
                'external_ref' => sprintf('%s-%02d', self::MARKER, $i + 1),
                'pipeline' => $row['pipeline'],
                'source' => $row['source'],
                'name' => $row['name'],
                'company' => $row['company'],
                'email' => $row['email'],
                'phone' => $row['phone'] ?? null,
                'score_factors' => $row['score_factors'] ?? null,
                'notes' => $row['notes'] ?? null,
                'captured_at' => now()->subDays(30 - $i)->setTime(9, 15),
            ]);

            // Walk it to its display stage through the real transition path so
            // the board and the stage-event history look genuine.
            foreach ($row['moves'] ?? [] as $stage) {
                $lead->transitionTo($stage, null);
            }

            $created++;
        }

        $this->info("Seeded {$created} dummy leads (external_ref prefix ".self::MARKER.').');
        $this->line('Remove them with: php artisan leads:seed-dummy --remove');

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $leads = Lead::withTrashed()->where('external_ref', 'like', self::MARKER.'%')->get();

        foreach ($leads as $lead) {
            $lead->stageEvents()->delete();
            $lead->forceDelete();
        }

        $this->info("Removed {$leads->count()} dummy leads.");

        return self::SUCCESS;
    }

    /**
     * Fictional Polish SMB/agency prospects spread across both funnels, all
     * four stages, every source, and the full tier range so a board shows the
     * whole colour surface a look has to handle.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Aneta Wilk', 'company' => 'Kwiaciarnia Przykładowa', 'email' => 'aneta@kwiaciarnia-przykladowa.test', 'phone' => '+48 100 000 201',
                'score_factors' => ['fit' => ['has-existing-site']],
                'notes' => 'Strona na kreatorze, chce sklep z bukietami na abonament.'],
            ['pipeline' => 'kiwwwi', 'source' => 'ads', 'name' => 'Rafal Zieba', 'company' => 'Serwis Rowerowy Testowy', 'email' => 'kontakt@serwis-testowy.test',
                'score_factors' => ['behaviour' => ['pricing-page-view']]],
            ['pipeline' => 'kiwwwi', 'source' => 'referral', 'name' => 'Dorota Kaczmarek', 'company' => 'Przedszkole Przykładowe', 'email' => 'biuro@przedszkole-przykladowe.test', 'phone' => '+48 100 000 202',
                'score_factors' => ['fit' => ['has-existing-site', 'budget-signal-5k']],
                'notes' => 'Polecenie od rodzica. Strona nieaktualna od 2019, potrzebny nabor online.'],
            ['pipeline' => 'kiwwwi', 'source' => 'social', 'name' => 'Michal Sobczak', 'company' => 'Food Truck Testowy', 'email' => 'kontakt@foodtruck-testowy.test'],
            ['pipeline' => 'kiwwwi', 'source' => 'other', 'name' => 'Iwona Baran', 'company' => 'Pracownia Ceramiki Przykładowa', 'email' => 'iwona@ceramika-przykladowa.test',
                'score_factors' => ['fit' => ['template-floor-signal']],
                'notes' => 'Budzet bardzo niski, pyta o szablon za 850 zl.'],

            ['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Tomasz Lewicki', 'company' => 'Meblarnia Testowa', 'email' => 't.lewicki@meblarnia-testowa.test', 'phone' => '+48 100 000 203',
                'score_factors' => ['fit' => ['industry-ecommerce-services', 'has-existing-site'], 'behaviour' => ['magnet-download']],
                'notes' => 'Rozmowa 20 min: chce konfigurator szafy na wymiar.',
                'moves' => ['conversation']],
            ['pipeline' => 'kiwwwi', 'source' => 'social', 'name' => 'Klaudia Nowicka', 'company' => 'Studio Paznokci Przykładowe', 'email' => 'hej@paznokcie-przykladowe.test',
                'score_factors' => ['behaviour' => ['repeat-visit', 'case-study-view']],
                'moves' => ['conversation']],
            ['pipeline' => 'kiwwwi', 'source' => 'ads', 'name' => 'Bartek Rudnicki', 'company' => 'Autodetailing Testowy', 'email' => 'bartek@autodetailing-testowy.test', 'phone' => '+48 100 000 204',
                'score_factors' => ['fit' => ['budget-signal-5k'], 'behaviour' => ['pricing-page-view', 'repeat-visit']],
                'notes' => 'Chce rezerwacje online i galerie przed/po.',
                'moves' => ['conversation']],

            ['pipeline' => 'kiwwwi', 'source' => 'referral', 'name' => 'Grzegorz Palka', 'company' => 'Hurtownia Elektryczna Przykładowa', 'email' => 'g.palka@hurtownia-przykladowa.test', 'phone' => '+48 100 000 207',
                'score_factors' => ['fit' => ['industry-ecommerce-services', 'has-existing-site', 'budget-signal-5k'], 'behaviour' => ['pricing-page-view']],
                'notes' => 'Oferta: B2B z cennikami per klient, 14 200 zl netto + opieka.',
                'moves' => ['conversation', 'offer']],
            ['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Sylwia Mazur', 'company' => 'Klinika Fizjoterapii Testowa', 'email' => 'recepcja@klinika-testowa.test',
                'score_factors' => ['fit' => ['has-existing-site', 'budget-signal-5k'], 'behaviour' => ['magnet-download', 'case-study-view']],
                'notes' => 'Oferta wyslana, czeka na decyzje wspolnika.',
                'moves' => ['conversation', 'offer']],

            ['pipeline' => 'kiwwwi', 'source' => 'referral', 'name' => 'Pawel Duda', 'company' => 'Kancelaria Przykładowa', 'email' => 'p.duda@kancelaria-przykladowa.test', 'phone' => '+48 100 000 206',
                'score_factors' => ['fit' => ['has-existing-site', 'budget-signal-5k']],
                'notes' => 'Podpisane. Start od nowej strony + blog prawniczy.',
                'moves' => ['conversation', 'offer', 'won']],

            ['pipeline' => 'filipchrapek', 'source' => 'outbound', 'name' => 'Kamil Ostrowski', 'company' => 'Agencja Przykładowa', 'email' => 'kamil@agencja-przykladowa.test',
                'score_factors' => ['fit' => ['agency-10-50-people', 'wp-woo-stack']],
                'notes' => 'Agencja 18 osob, sporo Woo. Wyslany value-first audyt LCP.'],
            ['pipeline' => 'filipchrapek', 'source' => 'outbound', 'name' => 'Marta Jaworska', 'company' => 'Studio Graficzne Testowe', 'email' => 'marta@studio-graficzne.test',
                'score_factors' => ['fit' => ['agency-10-50-people'], 'trigger' => ['senior-dev-job-ad']]],
            ['pipeline' => 'filipchrapek', 'source' => 'social', 'name' => 'Lukasz Wrona', 'company' => 'Web Studio Przykładowe', 'email' => 'l.wrona@webstudio-przykladowe.test',
                'score_factors' => ['fit' => ['wp-woo-stack', 'hires-senior-roles'], 'behaviour' => ['profile-view-or-post-engagement']],
                'moves' => ['conversation']],
            ['pipeline' => 'filipchrapek', 'source' => 'referral', 'name' => 'Ewa Stachura', 'company' => 'Agencja Brandingowa Testowa', 'email' => 'ewa@branding-testowy.test', 'phone' => '+48 100 000 205',
                'score_factors' => ['fit' => ['agency-10-50-people', 'wp-woo-stack'], 'trigger' => ['replatform-signal', 'new-cto-cmo'], 'behaviour' => ['case-study-visit']],
                'notes' => 'Nowy CTO chce zejsc z autorskiego CMS na Woo. Rozmowa techniczna zrobiona.',
                'moves' => ['conversation', 'offer']],
            ['pipeline' => 'filipchrapek', 'source' => 'outbound', 'name' => 'Adam Cieslak', 'company' => 'E-commerce Przykładowy', 'email' => 'adam@ecommerce-przykladowy.test',
                'score_factors' => ['fit' => ['agency-10-50-people', 'wp-woo-stack', 'hires-senior-roles'], 'trigger' => ['funding']],
                'notes' => 'Runda seed, skaluja zespol. Stala wspolpraca od pazdziernika.',
                'moves' => ['conversation', 'offer', 'won']],
        ];
    }
}
