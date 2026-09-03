<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Scheduled pull of contact-form submissions from the brand site into the
 * kiwwwi pipeline. Which forms are pulled is decided on the site, not here.
 *
 * Transport: a read-only JSON endpoint served by a mu-plugin on the site,
 * authenticated with a WP Application Password (Basic auth over HTTPS).
 * Pull, not webhook: this CRM is local-only Docker, so the site can never
 * reach it — same architecture as the trello/clockify sync commands.
 *
 * Credentials live in config/services.php (KIWWWI_LEADS_* env), not an
 * Integration row: there is no OAuth dance and no UI to manage, and the
 * endpoint is bespoke to this one site.
 *
 * Dedupe: external_ref = "ff-{submission id}", unique per account, looked up
 * withTrashed() — a re-pulled window only skips, and a lead soft-deleted in
 * the CRM stays deleted. `source` is derived from the submission's tracking
 * params at capture and is immutable afterwards (Lead::booted enforces it):
 * a Google Ads click id (gclid, or the Consent Mode era gbraid/wbraid/gad_*)
 * / utm_medium=cpc → ads, else www-form.
 */
final class KiwwwiLeadsService
{
    private const ENDPOINT = '/wp-json/kiwwwi/v1/lead-submissions';

    private const PIPELINE = 'kiwwwi';

    public function __construct(private readonly Account $account) {}

    /**
     * Pull submissions created at/after $since and upsert them as leads.
     *
     * @return array{fetched: int, created: int, skipped: int, malformed: int}
     */
    public function sync(?CarbonInterface $since = null): array
    {
        $stats = ['fetched' => 0, 'created' => 0, 'skipped' => 0, 'malformed' => 0];

        foreach ($this->fetchSubmissions($since) as $submission) {
            $stats['fetched']++;

            if (! is_array($submission) || ! is_numeric($submission['id'] ?? null)) {
                $stats['malformed']++;

                continue;
            }

            $externalRef = 'ff-'.(int) $submission['id'];

            // withTrashed is load-bearing: a lead deleted in the CRM must not
            // resurrect on the next pull (the WP entry may even be trashed by
            // then — the endpoint excludes trashed entries, but ids already
            // synced stay claimed either way).
            $exists = Lead::withTrashed()
                ->where('account_id', $this->account->id)
                ->where('external_ref', $externalRef)
                ->exists();

            if ($exists) {
                $stats['skipped']++;

                continue;
            }

            try {
                Lead::create($this->mapSubmission($submission, $externalRef));
                $stats['created']++;
            } catch (Throwable $e) {
                // One rotten submission (unparsable fields, config drift) must
                // not abort the whole pull — count it and move on.
                $stats['malformed']++;
            }
        }

        return $stats;
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchSubmissions(?CarbonInterface $since): array
    {
        $config = (array) config('services.kiwwwi.leads', []);

        $baseUrl = mb_rtrim((string) ($config['base_url'] ?? ''), '/');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['app_password'] ?? '');

        if ($baseUrl === '' || $username === '' || $password === '') {
            throw new RuntimeException(
                'Kiwwwi leads pull is not configured — set KIWWWI_LEADS_BASE_URL, '
                .'KIWWWI_LEADS_USERNAME and KIWWWI_LEADS_APP_PASSWORD.'
            );
        }

        $query = [];
        if ($since !== null) {
            $query['since'] = $since->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $payload = Http::withBasicAuth($username, $password)
            ->acceptJson()
            ->timeout(30)
            ->get($baseUrl.self::ENDPOINT, $query)
            ->throw()
            ->json();

        if (! is_array($payload) || ! is_array($payload['submissions'] ?? null)) {
            throw new RuntimeException('Kiwwwi lead endpoint returned an unexpected payload (no submissions array).');
        }

        return $payload['submissions'];
    }

    /**
     * Map one WP submission to Lead attributes. Field shapes per form:
     * form 3 — input_text (full name), email, phone;
     * form 6 — name{first_name,middle_name,last_name}, company_name, email,
     * phone, message, user_type, contact_preference.
     *
     * `stage` is deliberately not set: Lead::booted() derives the pipeline's
     * entry stage ('lead') and writes the null → entry_stage capture event.
     *
     * @param  array<string, mixed>  $submission
     * @return array<string, mixed>
     */
    private function mapSubmission(array $submission, string $externalRef): array
    {
        $fields = is_array($submission['response'] ?? null) ? $submission['response'] : [];
        $tracking = is_array($submission['utm'] ?? null) ? $submission['utm'] : [];
        $sourceUrl = (string) ($submission['source_url'] ?? '');

        $email = $this->stringField($fields, 'email');

        return [
            'account_id' => $this->account->id,
            'pipeline' => self::PIPELINE,
            'name' => $this->extractName($fields) ?? $email ?? 'FluentForm entry #'.(int) $submission['id'],
            'company' => $this->stringField($fields, 'company_name'),
            'email' => $email,
            'phone' => $this->stringField($fields, 'phone'),
            'source' => $this->detectSource($tracking),
            'external_ref' => $externalRef,
            'notes' => $this->buildNotes($fields, $tracking, $sourceUrl),
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
    private function buildNotes(array $fields, array $tracking, string $sourceUrl): ?string
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

        return $lines === [] ? null : implode("\n", $lines);
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
        foreach (['created_at_utc', 'created_at'] as $key) {
            $value = $submission[$key] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value);
                } catch (Throwable $e) {
                    // Try the next key, then fall through to now().
                }
            }
        }

        return CarbonImmutable::now();
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
