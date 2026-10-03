<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\ClientRetainer;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskBrief;
use App\Models\TaskSession;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentIdentity;
use App\Services\Agent\TaskBriefWriter;
use App\Services\Day\DayPlanner;
use App\Services\Tasks\TaskCompletion;
use App\Support\LocalCalendar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fictional demo data for the public demo instance (demo.crm-solo.com).
 *
 * Everything here is invented: agency, clients, leads, people, NIPs, emails.
 * Run AFTER `php artisan migrate:fresh --force`:
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * Never combine with DatabaseSeeder (it ships a known-password owner).
 * The demo owner password is printed at the end of the run; override
 * it by setting DEMO_PASSWORD in the environment before seeding.
 */
final class DemoSeeder extends Seeder
{
    private Account $account;

    private User $owner;

    private Client $zlotyMlyn;

    private int $invoiceSeq = 0;

    /** @var array<string, Task> */
    private array $tasks = [];

    /** @var list<TimeEntry> */
    private array $agentEntries = [];

    public function run(): void
    {
        if (User::withTrashed()->where('email', User::DEMO_EMAIL)->exists()) {
            $this->command->warn('Demo user already exists. Run `php artisan migrate:fresh --force` first, then reseed.');

            return;
        }

        $password = (string) config('app.demo_password') ?: Str::password(16, symbols: false);

        $this->account = Account::create([
            'name' => 'Brzoza Digital',
            'is_test' => true,
        ]);

        $this->owner = $this->account->users()->create([
            'first_name' => 'Anna',
            'last_name' => 'Zielińska',
            'email' => User::DEMO_EMAIL,
            'password' => $password,
        ]);
        $this->owner->forceFill([
            'owner' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->seedFurnitureWorkshop();
        $this->seedCafe();
        $this->seedFoundation();
        $this->seedMovementStudio();
        $this->seedLeads();
        $this->seedAgentWork();
        $this->seedDays();

        $this->command->info('');
        $this->command->info('Demo data seeded for account "Brzoza Digital".');
        $this->command->info('  Login:    '.User::DEMO_EMAIL);
        $this->command->info("  Password: {$password}");
        $this->command->info('');
        $this->command->info(sprintf(
            '  %d clients, %d leads, %d projects, %d tasks, %d time entries, %d invoices, %d reports, %d month-close runs',
            Client::count(),
            Lead::count(),
            Project::count(),
            Task::count(),
            TimeEntry::count(),
            Invoice::count(),
            ClientReport::count(),
            MonthCloseRun::count(),
        ));
    }

    // Client 1: retainer + maintenance month-close + reports (the showcase)
    private function seedFurnitureWorkshop(): void
    {
        $client = $this->makeClient([
            'name' => 'Pracownia Mebli Przykładowa',
            'email' => 'kontakt@meble-przykladowe.test',
            'phone' => '+48 100 000 101',
            'address' => 'ul. Stolarska 12',
            'city' => 'Łódź',
            'postal_code' => '90-410',
            'country' => 'PL',
            'tax_id' => '7311982045',
            'business_type' => 'sp. z o.o.',
            'segment' => 'smb',
            'cooperation_type' => 'retainer',
            'is_pinned' => true,
            'month_close_type' => 'maintenance',
            'report_mode' => 'report',
            'include_in_month_close' => true,
            'notes' => "Rodzinna pracownia mebli na wymiar. Strona firmowa na WordPressie + galeria realizacji.\nKontakt najlepiej mailowo, Marek odpisuje wieczorami.",
            'report_baseline_markdown' => <<<'MD'
## W ramach abonamentu
- monitoring dostępności strony i certyfikatu SSL
- aktualizacje WordPress, wtyczek oraz motywu
- cotygodniowa kopia zapasowa plików i bazy danych
- drobne poprawki treści zgłaszane mailowo
MD,
        ], createdMonthsAgo: 5, history: [
            ['active', 21, 'Umowa podpisana. Start opieki od początku miesiąca.'],
        ]);

        ClientLifecycleEvent::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'from_stage' => 'active',
            'to_stage' => 'active',
            'note' => 'Marek zapowiada rozbudowę strony o moduł sklepu jesienią. Wrócić do tematu pod koniec sierpnia.',
            'created_at' => now()->subDays(14)->setTime(10, 20),
        ]);

        $marek = $this->makeContact($client, 'Marek', 'Dąbek', ['marek@meble-przykladowe.test'], 'Właściciel', '+48 100 000 101');
        $this->makeContact($client, 'Ewa', 'Dąbek', ['ewa@meble-przykladowe.test'], 'Marketing', '+48 100 000 102');

        ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'label' => 'Opieka serwisowa WWW',
            'description' => 'Pakiet 8 h prac + aktualizacje i kopie zapasowe',
            'monthly_hours' => 8,
            'monthly_fee' => 1200,
            'overage_hourly_rate' => 165,
            'invoice_group' => 1,
            'vat_symbol' => '23',
            'rollover_cap_hours' => 16,
            'is_active' => true,
            'sort_order' => 0,
            'currency' => 'PLN',
            'effective_from' => now()->subMonthsNoOverflow(4)->startOfMonth()->toDateString(),
        ]);

        ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'label' => 'Hosting i utrzymanie',
            'description' => 'Serwer, domena, certyfikat SSL',
            'monthly_hours' => 0,
            'monthly_fee' => 190,
            'invoice_group' => 1,
            'vat_symbol' => '23',
            'is_active' => true,
            'sort_order' => 1,
            'currency' => 'PLN',
            'effective_from' => now()->subMonthsNoOverflow(4)->startOfMonth()->toDateString(),
        ]);

        $general = $client->ensureGeneralProject();
        $www = $this->makeProject($client, 'Strona WWW meble-przykladowe.test', 'Serwis firmowy: WordPress, galeria realizacji, formularze kontaktowe.');
        // Before the close is started: the run takes its site steps from this flag once.
        $www->update(['include_in_month_close' => true]);

        $formularz = $this->makeTask($www, 'Formularz wyceny mebli na wymiar', 'Doing', [
            'type' => 'feature',
            'priority' => 'high',
            'is_reportable' => true,
            'due_date' => now()->addDays(3),
            'description' => "Nowy formularz wyceny: wymiary, materiał, załącznik ze szkicem.\n\n- [x] szkielet formularza\n- [ ] walidacja pól\n- [ ] powiadomienie mailowe do pracowni",
        ]);
        $this->makeTask($www, 'Walidacja pól formularza wyceny', 'Doing', [
            'type' => 'feature',
            'parent_task_id' => $formularz->id,
            'is_reportable' => true,
        ]);
        $filtry = $this->makeTask($www, 'Sekcja realizacji: filtry kategorii', 'Backlog', ['type' => 'feature', 'priority' => 'medium']);
        $zdjecia = $this->makeTask($www, 'Optymalizacja zdjęć w galerii', 'Backlog', ['priority' => 'low']);
        $cennik = $this->makeTask($www, 'Aktualizacja cennika PDF', 'To-Do', ['priority' => 'medium', 'is_reportable' => true]);
        $rwd = $this->makeTask($www, 'Poprawka RWD nagłówka na tablecie', 'Testing', ['type' => 'bug', 'priority' => 'medium', 'is_reportable' => true]);
        $migracja = $this->makeTask($www, 'Migracja hostingu na PHP 8.3', 'Done', ['is_reportable' => true]);
        $updates = $this->makeTask($www, 'Aktualizacja WordPress i wtyczek (czerwiec)', 'Done', ['recurrence_period_days' => 30]);
        $this->makeTask($general, 'Przygotować ofertę rozbudowy o sklep', 'To-Do', ['priority' => 'medium']);

        // Time entries: last month + early current month.
        $lastMonth = $this->monthsAgo(1);
        $this->agentEntries[] = $this->makeTime($client, $www, $formularz, 'Formularz wyceny: szkielet i pola', 165, $lastMonth->copy()->setDay(9)->setTime(9, 30), 'terminal_session');
        $this->agentEntries[] = $this->makeTime($client, $www, $formularz, 'Formularz wyceny: obsługa załączników', 120, $lastMonth->copy()->setDay(16)->setTime(10, 0), 'terminal_session');
        $this->makeTime($client, $www, $formularz, 'Formularz wyceny: stylowanie i RWD', 90, $lastMonth->copy()->setDay(23)->setTime(13, 15));
        $this->makeTime($client, $www, $migracja, 'Migracja PHP 8.3: testy po przełączeniu', 105, $lastMonth->copy()->setDay(5)->setTime(11, 0));
        $this->makeTime($client, $www, $updates, 'Aktualizacje WP + wtyczki, kopia zapasowa', 45, $lastMonth->copy()->setDay(3)->setTime(8, 30));
        if ($slot = $this->recentSlot(12, 14, 40, 50)) {
            $this->makeTime($client, $www, null, 'Naprawa galerii po aktualizacji wtyczki', $slot[1], $slot[0], 'manual', 'Cofnięcie wtyczki galerii do poprzedniej wersji, testy na mobile.');
        }
        if ($slot = $this->recentSlot(3, 9, 0, 75)) {
            $this->makeTime($client, $www, $formularz, 'Formularz wyceny: walidacja pól', $slot[1], $slot[0], 'terminal_session');
        }

        // Reports: previous month finalized, last month draft.
        $may = $this->monthsAgo(2);
        $june = $this->monthsAgo(1);

        $finalized = ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'period_type' => 'month',
            'period_start' => $may->copy()->startOfMonth()->toDateString(),
            'period_end' => $may->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => 8,
            'actual_hours' => 7.5,
            'opening_balance_hours' => 0,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'status' => 'finalized',
            'generated_at' => $june->copy()->startOfMonth()->addDays(1)->setTime(9, 0)->setTimezone(config('app.timezone')),
            'finalized_at' => $june->copy()->startOfMonth()->addDays(2)->setTime(12, 30)->setTimezone(config('app.timezone')),
            'body_markdown' => <<<'MD'
# Raport miesięczny: maj 2026

## Prace rozwojowe

- Przygotowanie hostingu do migracji na PHP 8.3. Audyt wtyczek i pełna kopia zapasowa przed przełączeniem.
- Poprawki w sekcji "O pracowni" zgłoszone mailowo.
- Aktualizacja cennika w stopce.

## W ramach abonamentu
- monitoring dostępności strony i certyfikatu SSL
- aktualizacje WordPress, wtyczek oraz motywu
- cotygodniowa kopia zapasowa plików i bazy danych
- drobne poprawki treści zgłaszane mailowo

## Podsumowanie rozliczeniowe

Bilans na start: +0h

Pula okresu: +8h

Wykorzystano: −7.5h

Bilans na koniec: +0.5h
MD,
        ]);

        ClientReportRevision::create([
            'account_id' => $this->account->id,
            'client_report_id' => $finalized->id,
            'user_id' => $this->owner->id,
            'reason' => 'finalize',
            'body_markdown_before' => $finalized->body_markdown,
            'status_before' => 'draft',
            'contracted_hours_before' => 8,
            'actual_hours_before' => 7.5,
            'currency_before' => 'PLN',
            'composer_key_before' => 'structured_list',
            'created_at' => $june->copy()->startOfMonth()->addDays(2)->setTime(12, 30)->setTimezone(config('app.timezone')),
        ]);

        $draftBodyV1 = <<<'MD'
# Raport miesięczny: czerwiec 2026

## Prace rozwojowe

- Formularz wyceny mebli na wymiar.
- Migracja hostingu na PHP 8.3.
- Naprawa galerii realizacji po aktualizacji wtyczki.
MD;

        $draft = ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'period_type' => 'month',
            'period_start' => $june->copy()->startOfMonth()->toDateString(),
            'period_end' => $june->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => 8,
            'actual_hours' => 8.75,
            'opening_balance_hours' => 0.5,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'status' => 'draft',
            'generated_at' => now()->subDays(4)->setTime(8, 45),
            'body_markdown' => <<<'MD'
# Raport miesięczny: czerwiec 2026

## Prace rozwojowe

- Formularz wyceny mebli na wymiar: szkielet, obsługa załączników ze szkicem i stylowanie RWD.
- Migracja hostingu na PHP 8.3 wraz z testami po przełączeniu.
- Naprawa galerii realizacji po awaryjnej aktualizacji wtyczki.

## W ramach abonamentu
- monitoring dostępności strony i certyfikatu SSL
- aktualizacje WordPress, wtyczek oraz motywu
- cotygodniowa kopia zapasowa plików i bazy danych
- drobne poprawki treści zgłaszane mailowo

## Podsumowanie rozliczeniowe

Bilans na start: +0.5h

Pula okresu: +8h

Wykorzystano: −8.75h

Bilans na koniec: −0.25h
MD,
        ]);

        ClientReportRevision::create([
            'account_id' => $this->account->id,
            'client_report_id' => $draft->id,
            'user_id' => $this->owner->id,
            'reason' => 'update',
            'body_markdown_before' => $draftBodyV1,
            'status_before' => 'draft',
            'contracted_hours_before' => 8,
            'actual_hours_before' => 8.75,
            'currency_before' => 'PLN',
            'composer_key_before' => 'structured_list',
            'created_at' => now()->subDays(3)->setTime(16, 10),
        ]);

        // Invoices: monthly retainer billing across 2026 (netto in whole zł).
        $this->makeInvoice($client, '2026-02-05', 1390, true);
        $this->makeInvoice($client, '2026-03-05', 1390, true);
        $this->makeInvoice($client, '2026-04-06', 1390, true);
        $this->makeInvoice($client, '2026-05-05', 1390, true);
        $this->makeInvoice($client, '2026-06-05', 1525, true); // + nadwyżka godzin
        $this->makeInvoice($client, '2026-07-06', 1390, false); // wystawiona, niezapłacona

        // Month-close: maintenance run for last month, in progress.
        $run = MonthCloseRun::startFor($client, LocalCalendar::previousMonth());
        $this->tickSteps($run, [
            'db_archived' => ['done', 'Zrzut bazy w archiwum, SSL ważny do listopada.'],
            'local_db_import' => ['skipped', 'Same aktualizacje, lokalna baza wystarczy.'],
            'wp_updates' => ['done', null],
            'local_verify' => ['done', 'Formularze i galeria sprawdzone lokalnie.'],
            'reconcile_log' => ['done', null],
        ]);

        $this->tasks += compact('formularz', 'migracja', 'filtry', 'zdjecia', 'cennik', 'rwd');
    }

    // Client 2: hourly + gig month-close (completed)
    private function seedCafe(): void
    {
        $client = $this->zlotyMlyn = $this->makeClient([
            'name' => 'Kawiarnia Przykładowa',
            'email' => 'hej@kawiarnia-przykladowa.test',
            'phone' => '+48 100 000 103',
            'address' => 'Rynek Główny 8',
            'city' => 'Kraków',
            'postal_code' => '31-042',
            'country' => 'PL',
            'tax_id' => '6772301458',
            'business_type' => 'JDG',
            'segment' => 'smb',
            'cooperation_type' => 'hourly',
            'hourly_rate' => 160,
            'month_close_type' => 'gig',
            'report_mode' => 'summary_email',
            'include_in_month_close' => true,
            'notes' => 'Kawiarnia z paleniem na miejscu. Sklep online na WooCommerce, rozliczenie godzinowe.',
        ], createdMonthsAgo: 3, history: [
            ['active', 11, 'Start współpracy godzinowej: 160 zł netto/h, rozliczenie na koniec miesiąca.'],
        ]);

        $karolina = $this->makeContact($client, 'Karolina', 'Młynarska', ['hej@kawiarnia-przykladowa.test'], 'Właścicielka', '+48 100 000 103');

        $general = $client->ensureGeneralProject();
        $sklep = $this->makeProject($client, 'Sklep online', 'WooCommerce ze świeżo paloną kawą, subskrypcje i wysyłki.');

        $blik = $this->makeTask($sklep, 'Konfiguracja płatności BLIK', 'To-Do', [
            'priority' => 'high',
            'due_date' => now()->addDays(5),
            'description' => 'Podpięcie bramki z BLIK-iem i test sandbox. Klientka zgłasza, że połowa pytań na Instagramie to "czy jest BLIK".',
        ]);
        $ziarna = $this->makeTask($sklep, 'Podstrona "Nasze ziarna"', 'Doing', ['type' => 'feature', 'is_reportable' => true]);
        $this->makeTask($sklep, 'Aktualizacja menu sezonowego', 'Done', ['is_reportable' => true]);
        $this->makeTask($general, 'Zebrać zdjęcia z palarni do nowej galerii', 'Backlog', ['priority' => 'low']);

        $lastMonth = $this->monthsAgo(1);
        $this->makeTime($client, $sklep, $ziarna, 'Podstrona Nasze ziarna: layout', 120, $lastMonth->copy()->setDay(12)->setTime(15, 0));
        $this->makeTime($client, $sklep, $ziarna, 'Podstrona Nasze ziarna: treści i zdjęcia', 90, $lastMonth->copy()->setDay(19)->setTime(10, 30));
        $this->makeTime($client, $sklep, null, 'Menu sezonowe: podmiana i testy', 60, $lastMonth->copy()->setDay(26)->setTime(9, 0), 'manual', 'Nowe menu letnie, sprawdzenie na telefonie.');
        if ($slot = $this->recentSlot(2, 11, 30, 45)) {
            $this->makeTime($client, $sklep, $blik, 'BLIK: research bramek i sandbox', $slot[1], $slot[0]);
        }

        // Invoices: hourly billing, amount varies with hours worked (160 zł/h).
        $this->makeInvoice($client, '2026-04-08', 960, true);  // 6 h
        $this->makeInvoice($client, '2026-05-07', 720, true);  // 4,5 h
        $this->makeInvoice($client, '2026-06-09', 1120, true); // 7 h
        $this->makeInvoice($client, '2026-07-07', 480, false); // 3 h, niezapłacona

        $this->tasks['blik'] = $blik;

        // Gig month-close: everything done, run completed.
        $run = MonthCloseRun::startFor($client, LocalCalendar::previousMonth());
        $this->tickSteps($run, [
            'reconcile_log' => ['done', 'Godziny spięte z wpisami czasu, 4,5 h.'],
            'summary_email' => ['done', 'Podsumowanie wysłane mailem.'],
            'draft_invoice' => ['done', 'Szkic faktury: 4,5 h x 160 zł.'],
        ]);
        $run->refresh();
        if ($run->status !== MonthCloseRun::STATUS_COMPLETED) {
            $run->update(['status' => MonthCloseRun::STATUS_COMPLETED, 'completed_at' => now()->subDays(5)]);
        }
    }

    // Client 3: project-based, lighter footprint
    private function seedFoundation(): void
    {
        $client = $this->makeClient([
            'name' => 'Fundacja Testowa',
            'email' => 'biuro@fundacja-testowa.test',
            'phone' => '+48 100 000 104',
            'address' => 'ul. Widok 3/5',
            'city' => 'Warszawa',
            'postal_code' => '00-023',
            'country' => 'PL',
            'tax_id' => '5252718304',
            'business_type' => 'fundacja',
            'segment' => 'smb',
            'cooperation_type' => 'project',
            'notes' => 'Nowa strona fundacji rozliczana projektowo (wycena etapami).',
        ], createdMonthsAgo: 2, history: [
            ['active', 5, 'Zaakceptowany etap 1 (makiety i struktura treści). Całość: 3 etapy, 9 800 zł netto.'],
        ]);

        $tomasz = $this->makeContact($client, 'Tomasz', 'Gajda', ['t.gajda@fundacja-testowa.test'], 'Koordynator projektów');

        $general = $client->ensureGeneralProject();
        $strona = $this->makeProject($client, 'Nowa strona fundacji', 'Etap 1: makiety i struktura. Etap 2: wdrożenie. Etap 3: migracja treści.');

        $makiety = $this->makeTask($strona, 'Makiety: strona główna i podstrona projektu', 'Doing', ['type' => 'feature', 'priority' => 'medium', 'is_reportable' => true]);
        $this->makeTask($strona, 'Struktura treści z zespołem fundacji', 'Done', ['is_reportable' => true]);
        $hosting = $this->makeTask($strona, 'Wybór hostingu i domeny', 'To-Do', ['priority' => 'low']);
        $this->tasks += compact('makiety', 'hosting');
        $this->makeTask($strona, 'Moduł aktualności z tagami', 'Backlog', ['type' => 'feature']);

        $lastMonth = $this->monthsAgo(1);
        $this->makeTime($client, $strona, null, 'Warsztat: struktura treści', 150, $lastMonth->copy()->setDay(20)->setTime(10, 0), 'manual', 'Spotkanie online z zespołem fundacji, notatki w projekcie.');
        if ($slot = $this->recentSlot(8, 9, 0, 180)) {
            $this->makeTime($client, $strona, null, 'Makiety strony głównej', $slot[1], $slot[0]);
        }

        // Invoices: project billed in milestones (etapy).
        $this->makeInvoice($client, '2026-05-12', 3200, true);  // etap 1: makiety i struktura
        $this->makeInvoice($client, '2026-06-18', 4000, false); // etap 2: wdrożenie (niezapłacona)
    }

    // Client 4: churned (the third lifecycle stage on display)
    private function seedMovementStudio(): void
    {
        $client = $this->makeClient([
            'name' => 'Studio Ruchu Przykładowe',
            'email' => 'kontakt@studio-ruchu.test',
            'phone' => '+48 100 000 105',
            'address' => 'ul. Sienna 14',
            'city' => 'Gdańsk',
            'postal_code' => '80-605',
            'country' => 'PL',
            'tax_id' => '5841029384',
            'business_type' => 'JDG',
            'segment' => 'individual',
            'cooperation_type' => 'one_off',
            'lifecycle_stage' => 'churned',
            'notes' => 'Strona wizytówka dla studia pilatesu. Studio zamknięte wiosną, domena wygasa we wrześniu.',
        ], createdMonthsAgo: 6, history: [
            ['churned', 45, 'Studio kończy działalność. Strona wygaszona, kopia przekazana właścicielce.'],
        ]);

        $this->makeContact($client, 'Joanna', 'Balcer', ['kontakt@studio-ruchu.test'], 'Właścicielka', '+48 100 000 105');

        $this->makeInvoice($client, '2026-02-16', 2400, true); // strona wizytówka, jednorazowo
    }

    // Leads: both funnels populated across stages, one converted (won)
    private function seedLeads(): void
    {
        // The demo agency's own funnel names layered over the shipped
        // pipeline slugs (slug = attribution history, label = presentation).
        Setting::create([
            'account_id' => $this->account->id,
            'scope' => 'leadgen',
            'data' => [
                'pipeline_labels' => [
                    'kiwwwi' => 'Strony WWW',
                    'filipchrapek' => 'Konsulting',
                ],
            ],
        ]);

        // Hot: referral with an offer out (score 7 = gold).
        $this->makeLead([
            'pipeline' => 'kiwwwi',
            'source' => 'referral',
            'name' => 'Piotr Sowa',
            'company' => 'Software House Przykładowy',
            'email' => 'p.sowa@softwarehouse-przykladowy.test',
            'phone' => '+48 100 000 106',
            'score_factors' => ['fit' => ['industry-ecommerce-services', 'has-existing-site', 'budget-signal-5k']],
            'notes' => 'Software house szuka stałej opieki nad stroną i blogiem firmowym. Polecenie od Kawiarni Przykładowej.',
        ], capturedDaysAgo: 12, moves: [
            ['conversation', 9, 'Rozmowa z COO: zakres to opieka nad stroną i blog. Decyzja zarządu na dniach.'],
            ['offer', 7, 'Oferta wysłana: pakiet 6 h za 950 zł netto/mc, reakcja następnego dnia roboczego.'],
        ]);

        // Warm: Instagram DM mid-conversation (score 5 = oak).
        $this->makeLead([
            'pipeline' => 'kiwwwi',
            'source' => 'social',
            'name' => 'Marta Lis',
            'company' => 'Pracownia Wnętrz Testowa',
            'email' => 'marta@wnetrza-testowe.test',
            'score_factors' => ['behaviour' => ['magnet-download', 'pricing-page-view', 'repeat-visit']],
            'notes' => 'Pracownia projektowania wnętrz, obecna strona na kreatorze. Chce portfolio z prawdziwego zdarzenia.',
        ], capturedDaysAgo: 5, moves: [
            ['conversation', 4, 'DM na Instagramie: nowa strona z portfolio, budżet do doprecyzowania.'],
        ]);

        // Cold: fresh form submission, barely scored (rowan).
        $this->makeLead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'name' => 'Beata Nowak',
            'company' => 'Piekarnia Przykładowa',
            'email' => 'piekarnia@piekarnia-przykladowa.test',
            'score_factors' => ['fit' => ['has-existing-site']],
        ], capturedDaysAgo: 2);

        // The consulting funnel: outbound reply in conversation (score 6 = oak).
        $this->makeLead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'name' => 'Adrian Kruk',
            'company' => 'Studio Aplikacji Testowe',
            'email' => 'adrian@aplikacje-testowe.test',
            'score_factors' => ['fit' => ['wp-woo-stack'], 'trigger' => ['senior-dev-job-ad']],
            'notes' => 'Ogłoszenie na seniora przy sklepie B2B wisi od dwóch miesięcy - dobry moment na wsparcie zewnętrzne.',
        ], capturedDaysAgo: 8, moves: [
            ['conversation', 6, 'Odpowiedź na wiadomość: chętnie pogadają o wsparciu przy sklepie B2B.'],
        ]);

        // Won and converted: the coffee shop client came from the www form -
        // client_id keeps the channel attribution answerable.
        $won = $this->makeLead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'name' => 'Karolina Młynarska',
            'company' => 'Kawiarnia Przykładowa',
            'email' => 'hej@kawiarnia-przykladowa.test',
            'phone' => '+48 100 000 103',
            'score_factors' => ['fit' => ['industry-ecommerce-services', 'has-existing-site']],
        ], capturedDaysAgo: 104, moves: [
            ['conversation', 100, 'Telefon po wysłaniu formularza: sklep z kawą na WooCommerce kuleje po aktualizacji.'],
            ['offer', 96, 'Oferta: rozliczenie godzinowe 160 zł netto/h, start od poprawek sklepu.'],
            ['won', 92, 'Akceptacja stawki. Zakładam klienta i pierwsze zadania.'],
        ]);
        $won->forceFill(['client_id' => $this->zlotyMlyn->id])->save();
    }

    // The agent board on the furniture site: one card per lane, two ended sessions, a confirmed brief, an attachment.
    private function seedAgentWork(): void
    {
        $completion = app(TaskCompletion::class);
        $formularz = $this->tasks['formularz'];

        foreach (['filtry' => Task::AGENT_LANE_BACKLOG, 'formularz' => Task::AGENT_LANE_IN_PROGRESS, 'rwd' => Task::AGENT_LANE_IN_REVIEW] as $key => $lane) {
            $this->tasks[$key]->update(['cli' => 'claude', 'agent_lane' => $lane]);
        }
        $this->tasks['migracja']->update(['cli' => 'claude']);
        $completion->finish($this->tasks['migracja']->fresh());

        foreach ($this->agentEntries as $entry) {
            TaskSession::create([
                'task_id' => $formularz->id,
                'account_id' => $this->account->id,
                'cli' => 'claude',
                'base_branch' => 'main',
                'branch_name' => $formularz->sessionBranchName(),
                'worktree_path' => $formularz->sessionWorktreePath('/home/demo/meble-przykladowe'),
                'time_entry_id' => $entry->id,
                'started_at' => $entry->start_time,
                'ended_at' => $entry->end_time,
                'ended_reason' => 'stopped',
            ]);
        }

        app(TaskBriefWriter::class)->write($formularz, [
            'where' => 'Strona WWW meble-przykladowe.test, podstrona Wycena.',
            'done_when' => 'Formularz wysyła wycenę mailem do pracowni, a walidacja działa na telefonie.',
            'constraints' => 'Bez nowych wtyczek, na obecnym motywie.',
            'notes' => 'Szkic od klienta jest w załączniku.',
        ], true, new AgentIdentity($this->account, $this->owner), TaskBrief::VIA_CLI);

        $pdf = $this->demoPdf([
            'Brief: formularz wyceny mebli na wymiar',
            'Pola: szerokosc, wysokosc, glebokosc, material, szkic (PDF lub JPG).',
            'Po wyslaniu: mail do pracowni i potwierdzenie dla klienta.',
            'Dokument przykladowy do wersji demo.',
        ]);
        $path = "task-attachments/{$formularz->id}/".Str::uuid()->toString().'.pdf';
        if (! Storage::disk('local')->put($path, $pdf)) {
            throw new RuntimeException("Could not write the demo attachment to {$path}.");
        }
        TaskAttachment::create([
            'task_id' => $formularz->id,
            'file_path' => $path,
            'original_name' => 'brief-formularz-wyceny.pdf',
            'mime' => 'application/pdf',
            'size' => mb_strlen($pdf, '8bit'),
            'label' => 'Szkic od klienta',
        ]);
    }

    // Today, tomorrow and the month card, all from the local calendar so the nightly reset lands on the right days.
    private function seedDays(): void
    {
        $planner = app(DayPlanner::class);
        $today = LocalCalendar::today();
        $accountId = $this->account->id;

        foreach (['cennik', 'blik', 'makiety'] as $key) {
            $planner->addPick($accountId, $today, $this->tasks[$key]->id);
        }
        $planner->addPick($accountId, $today->addDay(), $this->tasks['hosting']->id);

        $blik = $this->tasks['blik'];
        $makiety = $this->tasks['makiety'];
        $zdjecia = $this->tasks['zdjecia'];
        $work = [[$blik, 'BLIK: konfiguracja bramki w sandboxie', 30], [$makiety, 'Makiety: poprawki po uwagach fundacji', 45], [$zdjecia, 'Galeria: kompresja zdjęć realizacji', 20]];
        foreach ($this->todayBlock(array_column($work, 2)) as $i => [$start, $minutes]) {
            [$task, $title] = $work[$i];
            $this->makeTime($task->project->client, $task->project, $task, $title, $minutes, $start);
        }

        $planner->completeTask($this->tasks['cennik']->fresh());

        $closed = 0;
        for ($day = $today->startOfMonth(); $day->lessThan($today); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            if ($closed++ % 4 === 0) {
                $start = Carbon::instance($day->setTime(9, 0));
                $this->makeTime($makiety->project->client, $makiety->project, $makiety, 'Makiety: przegląd z zespołem fundacji', 30, $start);
            }
            $planner->close($accountId, $day);
        }
    }

    /** The first day of the local month $months ago, in the display timezone. */
    private function monthsAgo(int $months): Carbon
    {
        return Carbon::instance(LocalCalendar::now()->startOfMonth()->subMonthsNoOverflow($months));
    }

    /**
     * Days ago at a local time, kept inside the current month so last month's hours still match its report; null when
     * only today is left, whose room belongs to today's own work.
     *
     * @return array{0: Carbon, 1: int}|null start and minutes
     */
    private function recentSlot(int $daysAgo, int $hour, int $minute, int $minutes): ?array
    {
        $day = LocalCalendar::today()->subDays($daysAgo)->max(LocalCalendar::today()->startOfMonth());

        return $day->isSameDay(LocalCalendar::today()) ? null : [Carbon::instance($day->setTime($hour, $minute)), $minutes];
    }

    /**
     * Today's entries back to back from 07:00, or ending just before the seed when it runs earlier; after local
     * midnight the last ones are shortened or left out, so none overlaps another or ends in the future.
     *
     * @param  list<int>  $lengths
     * @return array<int, array{0: Carbon, 1: int}> index => start and minutes
     */
    private function todayBlock(array $lengths): array
    {
        $gap = 5;
        $today = LocalCalendar::today();
        $latestEnd = LocalCalendar::now()->subMinutes($gap);
        $span = array_sum($lengths) + $gap * (count($lengths) - 1);
        $start = $today->setTime(7, 0);
        if ($start->addMinutes($span)->greaterThan($latestEnd)) {
            $start = $latestEnd->subMinutes($span)->max($today);
        }

        $slots = [];
        foreach ($lengths as $i => $length) {
            $minutes = min($length, intdiv($latestEnd->getTimestamp() - $start->getTimestamp(), 60));
            if ($minutes < 1) {
                break;
            }
            $slots[$i] = [Carbon::instance($start), $minutes];
            $start = $start->addMinutes($minutes + $gap);
        }

        return $slots;
    }

    /**
     * One page of ASCII text as a PDF built by hand: no document info dictionary, so no author, producer or dates.
     *
     * @param  list<string>  $lines
     */
    private function demoPdf(array $lines): string
    {
        $text = "BT /F1 12 Tf 56 780 Td 18 TL\n";
        foreach ($lines as $line) {
            $text .= '('.strtr($line, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).") Tj T*\n";
        }
        $text .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.mb_strlen($text, '8bit')." >>\nstream\n{$text}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = mb_strlen($pdf, '8bit');
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = mb_strlen($pdf, '8bit');
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: int, 2: string}>  $history  [stage, daysAgo, note]
     */
    private function makeClient(array $attributes, int $createdMonthsAgo, array $history): Client
    {
        $createdAt = now()->subMonthsNoOverflow($createdMonthsAgo)->setTime(9, 0);

        $client = Client::create(array_merge([
            'account_id' => $this->account->id,
            'type' => 'business',
            'country' => 'PL',
            'currency' => 'PLN',
            'lifecycle_stage' => 'active',
        ], $attributes));

        $client->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        // Backdate the auto-created "null -> stage" lifecycle event to the
        // creation date and start every story at active — pre-client stages
        // live in the Leads funnel now, not on the client lifecycle.
        $client->lifecycleEvents()->update([
            'to_stage' => 'active',
            'created_at' => $createdAt,
        ]);

        $fromStage = 'active';
        foreach ($history as [$stage, $daysAgo, $note]) {
            ClientLifecycleEvent::create([
                'account_id' => $this->account->id,
                'client_id' => $client->id,
                'user_id' => $this->owner->id,
                'from_stage' => $fromStage,
                'to_stage' => $stage,
                'note' => $note,
                'created_at' => now()->subDays($daysAgo)->setTime(11, 0),
            ]);
            $fromStage = $stage;
        }

        return $client;
    }

    /**
     * @param  list<string>  $emails
     */
    private function makeContact(Client $client, string $first, string $last, array $emails, ?string $position = null, ?string $phone = null): Contact
    {
        return Contact::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'first_name' => $first,
            'last_name' => $last,
            'emails' => $emails,
            'position' => $position,
            'phone' => $phone,
            'city' => $client->city,
            'country' => 'PL',
        ]);
    }

    /**
     * Creating the lead writes the capture event (`null -> entry stage`) at
     * captured_at via Lead::booted; each move goes through transitionTo so the
     * seeded funnel history obeys the same rules as the real one, then gets
     * its timestamp backdated to fit the story.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: int, 2: string}>  $moves  [stage, daysAgo, note]
     */
    private function makeLead(array $attributes, int $capturedDaysAgo, array $moves = []): Lead
    {
        $lead = Lead::create(array_merge([
            'account_id' => $this->account->id,
            'captured_at' => now()->subDays($capturedDaysAgo)->setTime(9, 30),
        ], $attributes));

        foreach ($moves as [$stage, $daysAgo, $note]) {
            $event = $lead->transitionTo($stage, $note, $this->owner);
            $event?->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(11, 15)])->save();
        }

        return $lead;
    }

    private function makeProject(Client $client, string $name, ?string $description = null): Project
    {
        return Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => $name,
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(Project $project, string $name, string $lane, array $attributes = []): Task
    {
        static $positions = [];

        $key = $project->id.'|'.$lane;
        $positions[$key] = ($positions[$key] ?? -1) + 1;

        return Task::create(array_merge([
            'project_id' => $project->id,
            'name' => $name,
            'list_name' => $lane,
            'position' => $positions[$key],
            'source' => 'manual',
            'is_completed' => $lane === 'Done',
            'is_reviewed' => true,
        ], $attributes));
    }

    private function makeTime(Client $client, Project $project, ?Task $task, string $title, int $minutes, Carbon $start, string $source = 'manual', ?string $description = null): TimeEntry
    {
        // Eloquent stores a Carbon's wall clock, so a local time is converted first.
        $start = $start->copy()->setTimezone(config('app.timezone'));

        return TimeEntry::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'source' => $source,
            'title' => $title,
            'description' => $description,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
            'billable' => true,
        ]);
    }

    /**
     * A PLN invoice mirrored from Infakt. Monetary columns are integer grosze;
     * $netZl is the net amount in whole złoty. Gross = net + 23% VAT. Paid
     * invoices settle ~12 days after issue; unpaid ones drive the cash-vs-accrual
     * split on the Revenue page.
     */
    private function makeInvoice(Client $client, string $date, int $netZl, bool $paid): Invoice
    {
        $this->invoiceSeq++;

        $net = $netZl * 100;
        $tax = (int) round($net * 0.23);
        $gross = $net + $tax;

        $issued = Carbon::parse($date);

        return Invoice::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'external_id' => sprintf('demo-inv-%04d', $this->invoiceSeq),
            'number' => sprintf('%d/%02d/%d', $this->invoiceSeq, $issued->month, $issued->year),
            'status' => $paid ? 'paid' : 'sent',
            'client_company_name' => $client->name,
            'client_tax_code' => $client->tax_id,
            'currency' => 'PLN',
            'net_price' => $net,
            'gross_price' => $gross,
            'tax_price' => $tax,
            'paid_price' => $paid ? $gross : 0,
            'left_to_pay' => $paid ? 0 : $gross,
            'invoice_date' => $issued->toDateString(),
            'sale_date' => $issued->copy()->endOfMonth()->toDateString(),
            'payment_date' => $issued->copy()->addDays(14)->toDateString(),
            'paid_date' => $paid ? $issued->copy()->addDays(12)->toDateString() : null,
        ]);
    }

    /**
     * @param  array<string, array{0: string, 1: ?string}>  $states  step_key => [state, note]
     */
    private function tickSteps(MonthCloseRun $run, array $states): void
    {
        foreach ($run->steps()->get() as $step) {
            if (! isset($states[$step->step_key])) {
                continue;
            }

            [$state, $note] = $states[$step->step_key];

            $step->update([
                'state' => $state,
                'note' => $note,
                'completed_at' => $state === 'done' ? now()->subDays(5)->setTime(9, 30) : null,
                'completed_by' => $state === 'done' ? $this->owner->id : null,
            ]);
        }

        $run->refreshStatusFromSteps();
    }
}
