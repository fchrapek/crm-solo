<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ClientsController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Routes, tabs and query keys are English: no retired word comes back. The list below is kept here on its own, so
 * a word dropped from the redirect table still fails this test.
 */
final class EnglishUrlsGuardTest extends TestCase
{
    private const array RETIRED = [
        // paths and tabs
        'jutro', 'tydzien', 'zadania', 'dzien', 'czesc', 'przeglad', 'dziennik', 'rozliczenia', 'dane', 'konto', 'wyglad',
        'leady', 'pola-klienta', 'integracje', 'formularze', 'agenci', 'nadawca', 'moduly', 'ponow',
        // query keys
        'widok', 'nastepny', 'na', 'z', 'ukryte', 'praca', 'wpisy', 'wszystkie', 'miesiac', 'miesiace', 'pomocnik', 'nowy',
        'kontakt', 'pokaz', 'kwoty', 'historia', 'zmien', 'faktura',
        // query values
        'dzis', 'gotowe', 'relacja', 'lista', 'ty', 'godziny', 'zakonczone', 'brutto', 'netto',
    ];

    /** Settings tabs that moved to another tab or to My profile: English words, retired only as a tab. */
    private const array RETIRED_SETTINGS_TABS = ['account', 'appearance', 'tasks', 'client-fields', 'sms', 'email', 'agents'];

    /** The one file allowed to hold the retired words, as the old half of each redirect. */
    private const string TABLE = 'app/Http/LegacyUrls.php';

    public function test_no_registered_route_has_a_retired_word_in_its_path(): void
    {
        $found = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RegisteredRoute $route): array => array_map(fn (string $segment): string => "{$route->uri()}: {$segment}", array_filter(
                explode('/', $route->uri()),
                fn (string $segment): bool => $this->retired($segment),
            )))
            ->values()
            ->all();

        $this->assertSame([], $found);
    }

    public function test_no_record_tab_is_a_retired_word(): void
    {
        $tabs = [...ClientsController::TABS, ...SettingsController::TABS];

        $this->assertSame([], array_values(array_filter($tabs, fn (string $tab): bool => $this->retired($tab))));
    }

    public function test_no_settings_tab_comes_back_under_a_retired_tab_name(): void
    {
        $this->assertSame([], array_values(array_intersect(SettingsController::TABS, self::RETIRED_SETTINGS_TABS)));

        foreach (self::RETIRED_SETTINGS_TABS as $tab) {
            $page = collect(Route::getRoutes()->getRoutes())->first(fn (RegisteredRoute $route): bool => in_array('GET', $route->methods(), true)
                && $route->matches(Request::create("/settings/{$tab}")));
            $this->assertNull($page, "GET /settings/{$tab} still reaches {$page?->uri()}");
        }
    }

    public function test_no_code_under_app_http_reads_or_writes_a_retired_word_as_a_string(): void
    {
        $found = [];
        foreach ($this->httpFiles() as $path => $source) {
            if ($path === self::TABLE) {
                continue;
            }
            preg_match_all("/(['\"])([a-z-]+)\\1/", $source, $literals);
            foreach (array_unique($literals[2]) as $literal) {
                if ($this->retired($literal)) {
                    $found[] = "{$path}: {$literal}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_every_query_key_read_under_app_http_is_english(): void
    {
        $found = [];
        foreach ($this->httpFiles() as $path => $source) {
            if ($path === self::TABLE) {
                continue;
            }
            preg_match_all("/(?:->|::)(?:query|boolean|input|has|filled|string|integer)\\(\\s*'([^']+)'/", $source, $keys);
            foreach ($keys[1] as $key) {
                if ($this->retired($key)) {
                    $found[] = "{$path}: {$key}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_redirect_table_is_the_only_file_skipped_and_holds_nothing_but_the_table(): void
    {
        $this->assertArrayHasKey(self::TABLE, $this->httpFiles());

        $outsideTheTable = preg_replace('/public const array (PATHS|QUERIES) = \[.*?\n    \];/s', '', $this->httpFiles()[self::TABLE]);
        $this->assertSame(2, mb_substr_count($this->httpFiles()[self::TABLE], 'public const array') - mb_substr_count((string) $outsideTheTable, 'public const array'));

        preg_match_all('/[a-z]+(?:-[a-z]+)?/', mb_strtolower((string) $outsideTheTable), $words);
        $this->assertSame([], array_values(array_unique(array_filter($words[0], fn (string $word): bool => $this->retired($word)))));
    }

    private function retired(string $word): bool
    {
        return in_array(mb_strtolower($word), self::RETIRED, true);
    }

    /** @return array<string, string> every PHP file under app/Http, by its path from the repository root */
    private function httpFiles(): array
    {
        $files = [];
        foreach (Finder::create()->files()->in(base_path('app/Http'))->name('*.php') as $file) {
            $files['app/Http/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }
        ksort($files);

        return $files;
    }
}
