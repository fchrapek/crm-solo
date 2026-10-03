<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\StagedLeadSubmission;
use App\Services\Leads\ClickIdScrubber;
use App\Services\Leads\MarketingConsent;
use App\Support\Redaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Scheduled pull of contact-form submissions from the brand sites into the
 * kiwwwi pipeline. Which forms are pulled is decided on each site, not here.
 *
 * Transport: a read-only JSON endpoint served by a mu-plugin on each site of
 * the multisite, authenticated with one WP Application Password (Basic auth
 * over HTTPS). Pull, not webhook: this CRM is local-only Docker, so the
 * sites can never reach it — same architecture as the trello sync command.
 *
 * Credentials and the endpoint list live in config/services.php
 * (KIWWWI_LEADS_* env), not an Integration row: there is no OAuth dance and
 * no UI to manage, and the endpoint is bespoke to this one multisite.
 *
 * Dedupe: external_ref = "{ref_prefix}{submission id}", unique per account,
 * looked up withTrashed() — a re-pulled window only skips, and a lead
 * soft-deleted in the CRM stays deleted. FluentForm ids are per-blog
 * sequences, so the endpoint's prefix is the namespace; the first site keeps
 * the bare "ff-" it used before there were two. Each lead's notes name its
 * site. `source` is derived from the submission's tracking params at capture
 * and is immutable afterwards (Lead::booted enforces it): a Google Ads click
 * id (gclid, or the Consent Mode era gbraid/wbraid/gad_*) / utm_medium=cpc →
 * ads, else www-form.
 *
 * Consent: the site records the visitor's ConsentLite state with each
 * submission (`consent`). Each click id survives only when the service that
 * issued it was granted (MarketingConsent::keptClickIds: gclid/gbraid/
 * wbraid/dclid need google_ads, fbclid needs meta_pixel, msclkid never).
 * The strip runs on the source URL, the tracking pairs and the assembled
 * notes (the visitor's message included) before the lead is saved. `source`
 * is derived first, from the unstripped params, so an ads lead stays an ads
 * lead either way. A staged row keeps the raw payload until it is imported.
 */
final class KiwwwiLeadsService
{
    private const ENDPOINT = '/wp-json/kiwwwi/v1/lead-submissions';

    private const PIPELINE = 'kiwwwi';

    /** Settings scope holding the per-endpoint pull cursors. */
    private const CURSOR_SCOPE = 'kiwwwi_lead_sync';

    /** The prefix of the single-site era, whose cursor was stored unkeyed. */
    private const LEGACY_REF_PREFIX = 'ff-';

    public function __construct(private readonly Account $account) {}

    /**
     * A loggable one-line cause: the driver's message for a query error (the
     * query exception's own message carries the bound values), and any URL cut
     * down to host and path so no query string or userinfo reaches a log.
     */
    /** @param list<string> $secrets cut out before the message is shortened, so a secret near the cutoff cannot leave a prefix behind */
    public static function describe(Throwable $e, array $secrets = []): string
    {
        $source = $e instanceof QueryException && $e->getPrevious() !== null ? $e->getPrevious() : $e;

        $message = (string) preg_replace_callback(
            '~https?://[^\s<>"\'()\[\]]+~i',
            static function (array $match): string {
                $parts = parse_url($match[0]);

                return is_array($parts) ? ($parts['host'] ?? '').($parts['path'] ?? '') : '[url]';
            },
            $source->getMessage()
        );

        $message = str_replace(Redaction::variants($secrets), '[redacted]', $message);

        return mb_substr($source::class.': '.$message, 0, 1000);
    }

    /**
     * Read every configured endpoint once, since $since, and count what it
     * returns: the same GET a sync makes, but nothing is staged, imported or
     * remembered, so it can check a server's connection without a trace.
     *
     * @return array<string, array{fetched: int, error: ?string}>
     */
    public function probe(CarbonInterface $since): array
    {
        $config = $this->configuration();
        $rows = [];

        foreach ($config['endpoints'] as $key => $endpoint) {
            if ($endpoint['base_url'] === null) {
                continue;
            }

            try {
                $payload = $this->fetchPayload($endpoint, $config['username'], $config['app_password'], $since);
                $rows[$key] = ['fetched' => count($payload['submissions']), 'error' => null];
            } catch (Throwable $e) {
                $rows[$key] = ['fetched' => 0, 'error' => self::describe($e, [$config['app_password']])];
            }
        }

        return $rows;
    }

    /**
     * Pull every configured endpoint from its own cursor (or $sinceOverride,
     * or everything with $full), stage each fetched batch, then import every
     * staged submission (these batches and any left by earlier runs).
     *
     * Nothing is imported until a fetched batch is staged, in one transaction
     * with that endpoint's cursor, so a run that fails or dies part-way leaves
     * every submission either a lead or a staged row the next run retries.
     * An endpoint that cannot be read is reported in its row and never stops
     * the others; staged rows are imported either way.
     *
     * @return array{fetched: int, staged: int, created: int, skipped: int, malformed: int, failed: int, given_up: int, endpoints: array<string, array{since: ?string, fetched: int, staged: int, skipped: int, malformed: int, error: ?Throwable}>}
     */
    public function sync(?CarbonInterface $sinceOverride = null, bool $full = false): array
    {
        $config = $this->configuration();

        $stats = ['fetched' => 0, 'staged' => 0, 'created' => 0, 'skipped' => 0, 'malformed' => 0, 'failed' => 0, 'given_up' => 0, 'endpoints' => []];

        foreach ($config['endpoints'] as $key => $endpoint) {
            if ($endpoint['base_url'] === null) {
                continue;
            }

            $row = ['since' => null, 'fetched' => 0, 'staged' => 0, 'skipped' => 0, 'malformed' => 0, 'error' => null];

            try {
                $since = $sinceOverride?->toImmutable() ?? ($full ? null : $this->cursor($key));
                $row['since'] = $since?->utc()->toIso8601String();

                $payload = $this->fetchPayload($endpoint, $config['username'], $config['app_password'], $since);
                $this->stage($key, $endpoint, $payload, $row);
            } catch (Throwable $e) {
                $row['error'] = $e;
            }

            foreach (['fetched', 'staged', 'skipped', 'malformed'] as $count) {
                $stats[$count] += $row[$count];
            }
            $stats['endpoints'][$key] = $row;
        }

        $this->importStaged($stats, $config['endpoints']);

        $stats['given_up'] = $this->stagedRows()->givenUp()->count();

        return $stats;
    }

    /**
     * Where an endpoint's next incremental pull starts: the newest submission
     * time of a fully staged batch from it. Installs that predate the cursor
     * fall back to the newest lead pulled under that endpoint's prefix.
     */
    public function cursor(string $endpointKey): ?CarbonImmutable
    {
        $endpoints = $this->configuration()['endpoints'];
        $endpoint = $endpoints[$endpointKey] ?? null;
        if ($endpoint === null) {
            return null;
        }

        $stored = $this->storedCursors($endpoints)[$endpointKey] ?? null;
        if (is_string($stored) && $stored !== '') {
            return CarbonImmutable::parse($stored);
        }

        $query = Lead::withTrashed()
            ->where('account_id', $this->account->id)
            ->where('pipeline', self::PIPELINE)
            ->where('external_ref', 'like', $endpoint['ref_prefix'].'%');

        // "ff-" is a string prefix of "ff-en-": a sibling namespace that
        // extends this one must not advance this endpoint's resume point.
        foreach ($endpoints as $other) {
            if ($other['ref_prefix'] !== $endpoint['ref_prefix'] && str_starts_with($other['ref_prefix'], $endpoint['ref_prefix'])) {
                $query->where('external_ref', 'not like', $other['ref_prefix'].'%');
            }
        }

        $lastCapturedAt = $query->max('captured_at');

        return $lastCapturedAt === null ? null : CarbonImmutable::parse($lastCapturedAt);
    }

    /**
     * Put given-up submissions back in the retry queue with a fresh attempt count.
     */
    public function resetGivenUp(): int
    {
        return $this->stagedRows()->givenUp()->update(['given_up_at' => null, 'attempts' => 0]);
    }

    /**
     * @return Collection<int, StagedLeadSubmission>
     */
    public function givenUpSubmissions(): Collection
    {
        return $this->stagedRows()->givenUp()->orderBy('id')->get();
    }

    /**
     * @param  array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}  $endpoint
     * @param  array{submissions: array<int, mixed>, site_time_zone: string}  $payload
     * @param  array<string, mixed>  $row
     */
    private function stage(string $key, array $endpoint, array $payload, array &$row): void
    {
        DB::transaction(function () use ($key, $endpoint, $payload, &$row): void {
            $newest = $this->cursor($key);

            foreach ($payload['submissions'] as $submission) {
                $row['fetched']++;

                if (! is_array($submission) || ! is_numeric($submission['id'] ?? null)) {
                    $row['malformed']++;
                    Log::warning('Kiwwwi lead pull skipped a submission without an id.', [
                        'endpoint' => $key,
                        'type' => get_debug_type($submission),
                    ]);

                    continue;
                }

                // created_at is the site's wall clock; the import needs its zone.
                $submission['site_time_zone'] = $payload['site_time_zone'];

                $time = $this->submissionTime($submission);
                if ($time !== null && ($newest === null || $time->greaterThan($newest))) {
                    $newest = $time;
                }

                $externalRef = $endpoint['ref_prefix'].(int) $submission['id'];

                // withTrashed is load-bearing: a lead deleted in the CRM must
                // not resurrect on the next pull.
                if ($this->isImported($externalRef)) {
                    $row['skipped']++;

                    continue;
                }

                $staged = $this->stagedRows()->where('external_ref', $externalRef)->first();
                if ($staged !== null) {
                    // Keep its attempt history; take the freshest copy of the payload.
                    $staged->update(['payload' => $submission]);
                } else {
                    StagedLeadSubmission::create([
                        'account_id' => $this->account->id,
                        'external_ref' => $externalRef,
                        'payload' => $submission,
                    ]);
                }
                $row['staged']++;
            }

            if ($newest !== null) {
                $this->storeCursor($key, $newest);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}>  $endpoints
     */
    private function importStaged(array &$stats, array $endpoints): void
    {
        $rows = $this->stagedRows()->pending()->orderBy('id')->get();

        foreach ($rows as $row) {
            if ($this->isImported($row->external_ref)) {
                $row->delete();

                continue;
            }

            try {
                DB::transaction(function () use ($row, $endpoints): void {
                    Lead::create($this->mapSubmission($row->payload, $row->external_ref, $this->siteLabelFor($row->external_ref, $endpoints)));
                    $row->delete();
                });
                $stats['created']++;
            } catch (Throwable $e) {
                $this->recordFailure($row, $e);
                $stats['failed']++;
            }
        }
    }

    private function isImported(string $externalRef): bool
    {
        return Lead::withTrashed()
            ->where('account_id', $this->account->id)
            ->where('external_ref', $externalRef)
            ->exists();
    }

    /**
     * If this write fails too, the run fails and the row stays staged as it was.
     */
    private function recordFailure(StagedLeadSubmission $row, Throwable $e): void
    {
        $attempts = $row->attempts + 1;
        $givenUp = $attempts >= StagedLeadSubmission::MAX_ATTEMPTS;
        $error = self::describe($e);

        $row->update([
            'error' => $error,
            'attempts' => $attempts,
            'given_up_at' => $givenUp ? now() : null,
        ]);

        $context = [
            'account_id' => $this->account->id,
            'external_ref' => $row->external_ref,
            'attempts' => $attempts,
            'error' => $error,
        ];

        if ($givenUp) {
            Log::error('Kiwwwi lead submission failed to import and is no longer retried; fix the cause, then run kiwwwi:sync-leads --retry-failed.', $context);
        } else {
            Log::warning('Kiwwwi lead submission failed to import; the next run retries it.', $context);
        }
    }

    /**
     * @return Builder<StagedLeadSubmission>
     */
    private function stagedRows(): Builder
    {
        return StagedLeadSubmission::query()->where('account_id', $this->account->id);
    }

    private function cursorSetting(): ?Setting
    {
        return Setting::query()
            ->where('account_id', $this->account->id)
            ->where('scope', self::CURSOR_SCOPE)
            ->first();
    }

    /**
     * Cursors keyed by endpoint. The single-site era stored one unkeyed
     * cursor; it belongs to the endpoint that kept the legacy prefix.
     *
     * @param  array<string, array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}>  $endpoints
     * @return array<string, string>
     */
    private function storedCursors(array $endpoints): array
    {
        $data = $this->cursorSetting()?->data ?? [];
        $cursors = is_array($data['cursors'] ?? null) ? $data['cursors'] : [];

        $legacy = $data['cursor'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            foreach ($endpoints as $key => $endpoint) {
                if ($endpoint['ref_prefix'] === self::LEGACY_REF_PREFIX) {
                    $cursors[$key] ??= $legacy;
                }
            }
        }

        return array_filter($cursors, is_string(...));
    }

    private function storeCursor(string $key, CarbonImmutable $cursor): void
    {
        $cursors = $this->storedCursors($this->configuration()['endpoints']);
        $cursors[$key] = $cursor->utc()->toIso8601String();

        Setting::updateOrCreate(
            ['account_id' => $this->account->id, 'scope' => self::CURSOR_SCOPE],
            ['data' => ['cursors' => $cursors]],
        );
    }

    /**
     * Validated credentials and endpoints. An endpoint without a base_url is
     * kept (its staged rows still need a site label) but never polled.
     *
     * @return array{username: string, app_password: string, endpoints: array<string, array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}>}
     */
    private function configuration(): array
    {
        $config = (array) config('services.kiwwwi.leads', []);

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['app_password'] ?? '');

        $endpoints = [];
        foreach ((array) ($config['endpoints'] ?? []) as $key => $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $prefix = (string) ($endpoint['ref_prefix'] ?? '');
            if ($prefix === '') {
                throw new RuntimeException("Kiwwwi leads endpoint [{$key}] has no ref_prefix, so its submissions have no dedupe namespace.");
            }

            $baseUrl = mb_rtrim((string) ($endpoint['base_url'] ?? ''), '/');
            $label = $endpoint['site_label'] ?? null;
            $host = $baseUrl === '' ? null : parse_url($baseUrl, PHP_URL_HOST);

            $endpoints[(string) $key] = [
                'base_url' => $baseUrl === '' ? null : $baseUrl,
                'site' => is_numeric($endpoint['site'] ?? null) ? (int) $endpoint['site'] : null,
                'site_label' => is_string($label) && $label !== '' ? $label : (is_string($host) ? $host : null),
                'ref_prefix' => $prefix,
            ];
        }

        $polled = array_filter($endpoints, fn (array $endpoint): bool => $endpoint['base_url'] !== null);
        if ($username === '' || $password === '' || $polled === []) {
            throw new RuntimeException(
                'Kiwwwi leads pull is not configured — set KIWWWI_LEADS_USERNAME, '
                .'KIWWWI_LEADS_APP_PASSWORD and at least one site URL (KIWWWI_LEADS_BASE_URL).'
            );
        }

        // Distinct prefixes are the cross-site dedupe: two endpoints sharing
        // one would drop every id the other synced first.
        $prefixes = array_column($endpoints, 'ref_prefix');
        if (count($prefixes) !== count(array_unique($prefixes))) {
            throw new RuntimeException('Kiwwwi leads endpoints must have distinct ref_prefix values.');
        }

        return ['username' => $username, 'app_password' => $password, 'endpoints' => $endpoints];
    }

    /**
     * The endpoint a ref came from is the one with the longest matching prefix.
     *
     * @param  array<string, array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}>  $endpoints
     */
    private function siteLabelFor(string $externalRef, array $endpoints): ?string
    {
        $match = null;
        foreach ($endpoints as $endpoint) {
            if (str_starts_with($externalRef, $endpoint['ref_prefix'])
                && ($match === null || mb_strlen($endpoint['ref_prefix']) > mb_strlen($match['ref_prefix']))) {
                $match = $endpoint;
            }
        }

        return $match['site_label'] ?? null;
    }

    /**
     * @param  array{base_url: ?string, site: ?int, site_label: ?string, ref_prefix: string}  $endpoint
     * @return array{submissions: array<int, mixed>, site_time_zone: string}
     */
    private function fetchPayload(array $endpoint, string $username, string $password, ?CarbonInterface $since): array
    {
        $query = [];
        if ($since !== null) {
            $query['since'] = $since->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        try {
            $response = Http::withBasicAuth($username, $password)
                ->acceptJson()
                ->timeout(30)
                ->get($endpoint['base_url'].self::ENDPOINT, $query);
        } catch (ConnectionException $e) {
            throw new KiwwwiLeadsPullFailed(
                'Kiwwwi lead endpoint unreachable: '.self::describe($e),
                ['endpoint' => self::ENDPOINT, 'exception' => $e::class],
                $e,
            );
        }

        if ($response->failed()) {
            throw new KiwwwiLeadsPullFailed(
                "Kiwwwi lead endpoint answered HTTP {$response->status()}.",
                ['endpoint' => self::ENDPOINT, 'status' => $response->status()],
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['submissions'] ?? null)) {
            throw new KiwwwiLeadsPullFailed(
                'Kiwwwi lead endpoint returned an unexpected payload (no submissions array).',
                ['endpoint' => self::ENDPOINT, 'status' => $response->status()],
            );
        }

        // Two base URLs pointing at the same blog would import every
        // submission twice under two prefixes.
        if ($endpoint['site'] !== null && is_numeric($payload['site'] ?? null) && (int) $payload['site'] !== $endpoint['site']) {
            throw new KiwwwiLeadsPullFailed(
                sprintf('Kiwwwi lead endpoint answered as site %d, expected site %d; the site URLs look swapped or duplicated.', (int) $payload['site'], $endpoint['site']),
                ['endpoint' => self::ENDPOINT, 'site' => (int) $payload['site'], 'expected_site' => $endpoint['site']],
            );
        }

        $zone = $payload['site_time_zone'] ?? null;

        return [
            'submissions' => $payload['submissions'],
            'site_time_zone' => is_string($zone) && $zone !== '' ? $zone : 'UTC',
        ];
    }

    /**
     * Map one WP submission to Lead attributes. Field shapes per form:
     * form 3 — input_text (full name), email, phone;
     * form 6 — name{first_name,middle_name,last_name}, company_name, email,
     * phone, message, user_type, contact_preference.
     *
     * `stage` is deliberately not set: Lead::booted() derives the pipeline's
     * entry stage ('new') and writes the null → entry_stage capture event.
     *
     * @param  array<string, mixed>  $submission
     * @return array<string, mixed>
     */
    private function mapSubmission(array $submission, string $externalRef, ?string $siteLabel): array
    {
        $fields = is_array($submission['response'] ?? null) ? $submission['response'] : [];
        $tracking = is_array($submission['utm'] ?? null) ? $submission['utm'] : [];
        $sourceUrl = (string) ($submission['source_url'] ?? '');

        // Attribution first, from the full params: it survives the strip.
        $source = $this->detectSource($tracking);

        $snapshot = $submission['consent'] ?? null;
        $consent = MarketingConsent::fromSnapshot($snapshot);
        $keep = MarketingConsent::keptClickIds($snapshot);
        $tracking = ClickIdScrubber::tracking($tracking, $keep);
        $sourceUrl = ClickIdScrubber::url($sourceUrl, $keep);

        $consentLine = $consent.(MarketingConsent::cookieAbsent($snapshot) ? ' (no consent cookie at submit)' : '');

        $email = $this->stringField($fields, 'email');

        return [
            'account_id' => $this->account->id,
            'pipeline' => self::PIPELINE,
            'name' => $this->extractName($fields) ?? $email ?? 'FluentForm entry #'.(int) $submission['id'],
            'company' => $this->stringField($fields, 'company_name'),
            'email' => $email,
            'phone' => $this->stringField($fields, 'phone'),
            'source' => $source,
            'marketing_consent' => MarketingConsent::toColumn($consent),
            'external_ref' => $externalRef,
            // The message is the visitor's own text and can hold a pasted
            // landing URL: the whole assembled note goes through the scrub.
            'notes' => ClickIdScrubber::text($this->buildNotes($fields, $tracking, $sourceUrl, $siteLabel, $consentLine), $keep),
            'captured_at' => $this->parseCapturedAt($submission),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function extractName(array $fields): ?string
    {
        // Form 6: nested name parts.
        if (is_array($fields['name'] ?? null)) {
            $parts = array_filter([
                $fields['name']['first_name'] ?? null,
                $fields['name']['middle_name'] ?? null,
                $fields['name']['last_name'] ?? null,
            ], fn ($part) => is_string($part) && mb_trim($part) !== '');

            if ($parts !== []) {
                return mb_trim(implode(' ', array_map(fn ($part) => mb_trim((string) $part), $parts)));
            }
        }

        // Form 3: a single free-text name field.
        return $this->stringField($fields, 'input_text');
    }

    /**
     * The wiring contract (vault: projects/kiwwwi/lead-pipeline-wiring.md):
     * a Google Ads click id or utm_medium=cpc marks the paid channel; every
     * other form submission is the website-form channel. Both slugs
     * come from config/leadgen.php's source taxonomy.
     *
     * @param  array<string, mixed>  $tracking
     */
    private function detectSource(array $tracking): string
    {
        // Same key parser as the scrubber: GCLID or gclid[] still mean ads.
        $canonical = [];
        foreach ($tracking as $key => $value) {
            $canonical[ClickIdScrubber::canonicalKey((string) $key)] ??= $value;
        }
        $tracking = $canonical;

        $medium = mb_strtolower((string) ($tracking['utm_medium'] ?? ''));

        // Consent Mode denied-by-default strips gclid from ad landings — those
        // clicks carry gbraid/wbraid/gad_* instead (real example: submission
        // #132, ?gad_source=1&gad_campaignid=…&gbraid=…).
        $adsClickIds = ['gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid'];
        foreach ($adsClickIds as $param) {
            if (($tracking[$param] ?? null) !== null) {
                return 'ads';
            }
        }

        if (in_array($medium, ['cpc', 'ppc', 'paid'], true)) {
            return 'ads';
        }

        return 'www-form';
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $tracking
     */
    private function buildNotes(array $fields, array $tracking, string $sourceUrl, ?string $siteLabel, string $consent): string
    {
        $lines = [];

        if (($message = $this->stringField($fields, 'message')) !== null) {
            $lines[] = $message;
        }
        if (($userType = $this->stringField($fields, 'user_type')) !== null) {
            $lines[] = 'User type: '.$userType;
        }
        if (($preference = $this->stringField($fields, 'contact_preference')) !== null) {
            $lines[] = 'Contact preference: '.$preference;
        }
        if ($siteLabel !== null) {
            $lines[] = 'Site: '.$siteLabel;
        }
        if ($sourceUrl !== '') {
            $lines[] = 'Source URL: '.$sourceUrl;
        }
        if ($tracking !== []) {
            $pairs = [];
            foreach ($tracking as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $pairs[] = $key.'='.$value;
                }
            }
            if ($pairs !== []) {
                $lines[] = 'Tracking: '.implode(', ', $pairs);
            }
        }

        $lines[] = 'Marketing consent: '.$consent;

        return implode("\n", $lines);
    }

    /**
     * WP timestamps win over sync time: captured_at feeds the funnel's
     * Month-2 measured-rate swap, and a 15-minute pull lag (or a backfill)
     * must not shift when the lead actually arrived.
     *
     * @param  array<string, mixed>  $submission
     */
    private function parseCapturedAt(array $submission): CarbonImmutable
    {
        return $this->submissionTime($submission) ?? CarbonImmutable::now();
    }

    /**
     * created_at_utc is authoritative; created_at is the site's wall clock
     * (FluentForm stores current_time('mysql')), read in the site's zone.
     *
     * @param  array<string, mixed>  $submission
     */
    private function submissionTime(array $submission): ?CarbonImmutable
    {
        $zone = is_string($submission['site_time_zone'] ?? null) ? $submission['site_time_zone'] : 'UTC';

        foreach (['created_at_utc' => 'UTC', 'created_at' => $zone] as $key => $timeZone) {
            $value = $submission[$key] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value, $timeZone)->utc();
                } catch (Throwable $e) {
                    // Try the next key.
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function stringField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
