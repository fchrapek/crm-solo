<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Lead;
use App\Services\Leads\ClickIdScrubber;
use App\Services\Leads\MarketingConsent;
use Illuminate\Support\Facades\Http;

/*
 * A kiwwwi lead keeps a click id only when the visitor granted the service
 * that issued it; `source` is derived before the strip, so ads attribution
 * survives in every case.
 */

beforeEach(function () {
    $this->account = Account::create(['name' => 'Acc']);

    config()->set('services.kiwwwi.leads', [
        'username' => 'leadsync',
        'app_password' => 'test-app-password',
        'endpoints' => [
            'pl' => ['base_url' => 'https://kiwwwi.pl', 'site' => 1, 'ref_prefix' => 'ff-'],
        ],
    ]);
});

function adsSubmission(int $id, mixed $consent, bool $withConsentKey = true): array
{
    $submission = [
        'id' => $id,
        'form_id' => 6,
        'status' => 'unread',
        'created_at' => '2026-09-29 12:00:00',
        'created_at_utc' => '2026-09-29T10:00:00Z',
        'source_url' => 'https://kiwwwi.pl/kontakt/?utm_source=google&utm_medium=cpc&gclid=GCLID-1&gad_source=1&gbraid=GBRAID-1&wbraid=WBRAID-1#form',
        'response' => [
            'name' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
            'email' => "anna{$id}@example.com",
            'message' => 'Prosze o wycene.',
        ],
        'utm' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'gclid' => 'GCLID-1',
            'gad_source' => '1',
            'gbraid' => 'GBRAID-1',
            'wbraid' => 'WBRAID-1',
        ],
    ];

    if ($withConsentKey) {
        $submission['consent'] = $consent;
    }

    return $submission;
}

function consentSnapshot(array $categories, array $services = [], bool $cookie = true, bool $valid = true): array
{
    return [
        'v' => 2,
        'recorded_at' => '2026-09-29T10:00:00Z',
        'cookie_present' => $cookie,
        'valid' => $valid,
        'categories' => $categories,
        'services' => $services,
        'services_present' => true,
        'revision' => 0,
        'consent_id' => 'abc-123',
        'consent_timestamp' => '2026-09-29T09:59:00.000Z',
    ];
}

function fakeKiwwwiEndpoint(array $submissions): void
{
    Http::fake([
        'kiwwwi.pl/wp-json/kiwwwi/v1/lead-submissions*' => Http::response([
            'site' => 1,
            'site_time_zone' => 'Europe/Warsaw',
            'count' => count($submissions),
            'submissions' => $submissions,
        ]),
    ]);
}

function expectNoClickIds(Lead $lead): void
{
    $notes = (string) $lead->notes;
    expect($notes)
        ->not->toContain('GCLID-1')
        ->not->toContain('GBRAID-1')
        ->not->toContain('WBRAID-1')
        ->not->toContain('gclid=')
        // Campaign-level params are not identifiers and stay.
        ->toContain('utm_medium=cpc')
        ->toContain('gad_source=1')
        ->toContain('Source URL: https://kiwwwi.pl/kontakt/?utm_source=google&utm_medium=cpc&gad_source=1#form');
}

test('granted marketing consent keeps the click ids', function () {
    fakeKiwwwiEndpoint([adsSubmission(1, consentSnapshot(
        ['necessary', 'analytics', 'marketing'],
        ['analytics' => ['google_analytics'], 'marketing' => ['google_ads']],
    ))]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-1')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBe('granted')
        ->and($lead->notes)->toContain('gclid=GCLID-1')
        ->and($lead->notes)->toContain('gbraid=GBRAID-1')
        ->and($lead->notes)->toContain('wbraid=WBRAID-1')
        ->and($lead->notes)->toContain('Marketing consent: granted');
});

test('denied marketing consent strips click ids but keeps ads attribution', function () {
    fakeKiwwwiEndpoint([adsSubmission(2, consentSnapshot(['necessary', 'analytics']))]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-2')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBe('denied')
        ->and($lead->notes)->toContain('Marketing consent: denied');
    expectNoClickIds($lead);
});

test('marketing accepted with google_ads rejected inside it counts as denied', function () {
    fakeKiwwwiEndpoint([adsSubmission(3, consentSnapshot(
        ['necessary', 'marketing'],
        ['marketing' => ['meta_pixel']],
    ))]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-3')->sole();
    expect($lead->marketing_consent)->toBe('denied');
    expectNoClickIds($lead);
});

test('no consent cookie at submit is unknown, not denied, and still strips', function () {
    fakeKiwwwiEndpoint([adsSubmission(4, consentSnapshot([], [], cookie: false, valid: false))]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-4')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBeNull()
        ->and($lead->notes)->toContain('Marketing consent: unknown (no consent cookie at submit)');
    expectNoClickIds($lead);
});

test('an unparsable consent record is unknown and strips click ids', function () {
    fakeKiwwwiEndpoint([adsSubmission(5, consentSnapshot([], [], cookie: true, valid: false))]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-5')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBeNull()
        ->and($lead->notes)->toContain('Marketing consent: unknown');
    expectNoClickIds($lead);
});

test('a null consent (entry older than the site recording) is unknown and strips click ids', function () {
    fakeKiwwwiEndpoint([adsSubmission(6, null)]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-6')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBeNull();
    expectNoClickIds($lead);
});

test('a missing consent key (endpoint before 1.3.0) is unknown and strips click ids', function () {
    fakeKiwwwiEndpoint([adsSubmission(7, null, withConsentKey: false)]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-7')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->marketing_consent)->toBeNull();
    expectNoClickIds($lead);
});

test('a gclid-only ads click stays ads after the strip', function () {
    $submission = adsSubmission(8, null);
    $submission['source_url'] = 'https://kiwwwi.pl/?gclid=GCLID-1';
    $submission['utm'] = ['gclid' => 'GCLID-1'];
    fakeKiwwwiEndpoint([$submission]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-8')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->notes)->not->toContain('GCLID-1')
        ->and($lead->notes)->not->toContain('Tracking:')
        ->and($lead->notes)->toContain('Source URL: https://kiwwwi.pl/');
});

test('an accepted category is not service consent: empty or missing services never grant', function () {
    // ConsentLite has no marketing category without services, so an empty
    // list means google_ads was not accepted, and no services map is no evidence.
    expect(MarketingConsent::fromSnapshot(consentSnapshot(['necessary', 'marketing'], ['marketing' => []])))->toBe('denied')
        ->and(MarketingConsent::fromSnapshot(consentSnapshot(['necessary', 'marketing'])))->toBe('unknown')
        ->and(MarketingConsent::fromSnapshot(consentSnapshot(['necessary', 'marketing'], ['analytics' => ['google_analytics']])))->toBe('unknown')
        ->and(MarketingConsent::fromSnapshot(consentSnapshot(['necessary'], ['marketing' => ['google_ads']])))->toBe('denied')
        ->and(MarketingConsent::fromSnapshot(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']])))->toBe('granted');
});

test('malformed snapshots are unknown and never normalised into a grant', function (array $snapshot) {
    expect(MarketingConsent::fromSnapshot($snapshot))->toBe('unknown')
        ->and(MarketingConsent::keptClickIds($snapshot))->toBe([]);
})->with([
    'spaced category' => [consentSnapshot(['necessary', 'mark eting'], ['marketing' => ['google_ads']])],
    'upper-case category' => [consentSnapshot(['necessary', 'Marketing'], ['marketing' => ['google_ads']])],
    'padded category' => [consentSnapshot(['necessary', ' marketing'], ['marketing' => ['google_ads']])],
    'spaced service' => [consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google ads']])],
    'padded service key' => [consentSnapshot(['necessary', 'marketing'], ['marketing ' => ['google_ads']])],
    'services as a list' => [array_merge(consentSnapshot(['necessary', 'marketing']), ['services' => [['google_ads']]])],
    'service list as a map' => [consentSnapshot(['necessary', 'marketing'], ['marketing' => ['x' => 'google_ads']])],
    'non-string service' => [consentSnapshot(['necessary', 'marketing'], ['marketing' => [true]])],
    'categories as a string' => [array_merge(consentSnapshot([]), ['categories' => 'marketing'])],
    'no necessary category' => [consentSnapshot(['marketing'], ['marketing' => ['google_ads']])],
    'old v1 record' => [array_merge(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]), ['v' => 1])],
    'version as string' => [array_merge(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]), ['v' => '2'])],
    'revision as string' => [array_merge(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]), ['revision' => '0'])],
    'no revision' => [array_merge(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]), ['revision' => null])],
    'valid without cookie' => [consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']], cookie: false, valid: true)],
    'valid as string' => [array_merge(consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]), ['valid' => 'true'])],
    'empty array' => [[]],
]);

test('a cookie from another banner revision does not count', function () {
    config()->set('services.kiwwwi.leads.consent_revision', 3);
    $snapshot = consentSnapshot(['necessary', 'marketing'], ['marketing' => ['google_ads']]);

    expect(MarketingConsent::fromSnapshot($snapshot))->toBe('unknown')
        ->and(MarketingConsent::fromSnapshot(array_merge($snapshot, ['revision' => 3])))->toBe('granted');
});

test('garbage that is not an array is unknown', function () {
    expect(MarketingConsent::fromSnapshot('granted'))->toBe('unknown')
        ->and(MarketingConsent::fromSnapshot(null))->toBe('unknown')
        ->and(MarketingConsent::fromSnapshot(1))->toBe('unknown');
});

test('google consent keeps only google click ids: fbclid and msclkid go', function () {
    $submission = adsSubmission(20, consentSnapshot(
        ['necessary', 'analytics', 'marketing'],
        ['analytics' => ['google_analytics'], 'marketing' => ['google_ads']],
    ));
    $submission['source_url'] .= '&fbclid=FBCLID-1&msclkid=MSCLKID-1';
    $submission['source_url'] = str_replace('#form', '', $submission['source_url']).'#form';
    $submission['utm'] += ['fbclid' => 'FBCLID-1', 'msclkid' => 'MSCLKID-1'];
    fakeKiwwwiEndpoint([$submission]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $notes = (string) Lead::where('external_ref', 'ff-20')->sole()->notes;
    expect($notes)->toContain('gclid=GCLID-1')
        ->toContain('gbraid=GBRAID-1')
        ->not->toContain('FBCLID-1')
        ->not->toContain('MSCLKID-1')
        ->not->toContain('fbclid')
        ->not->toContain('msclkid');
});

test('meta consent without google keeps fbclid only', function () {
    $submission = adsSubmission(21, consentSnapshot(['necessary', 'marketing'], ['marketing' => ['meta_pixel']]));
    $submission['source_url'] = 'https://kiwwwi.pl/?gclid=GCLID-1&fbclid=FBCLID-1&msclkid=MSCLKID-1';
    $submission['utm'] = ['gclid' => 'GCLID-1', 'fbclid' => 'FBCLID-1', 'msclkid' => 'MSCLKID-1'];
    fakeKiwwwiEndpoint([$submission]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-21')->sole();
    expect($lead->marketing_consent)->toBe('denied')
        ->and($lead->source)->toBe('ads')
        ->and($lead->notes)->toContain('fbclid=FBCLID-1')
        ->and($lead->notes)->not->toContain('GCLID-1')
        ->and($lead->notes)->not->toContain('MSCLKID-1');
});

test('upper-case, encoded, array and fragment click ids are stripped at import, message included', function () {
    $submission = adsSubmission(22, null);
    $submission['source_url'] = 'https://kiwwwi.pl/kontakt/?utm_medium=cpc&GCLID=UPPER-1&%67braid=ENC-1&wbraid%5B%5D=ARR-1#gclid=FRAG-1';
    $submission['utm'] = ['utm_medium' => 'cpc', 'GCLID' => 'UPPER-1'];
    $submission['response']['message'] = 'Wchodzilem z https://kiwwwi.pl/?Gclid=MSG-1&x=1 i mam kod gbraid=MSG-2.
Dzieki!';
    fakeKiwwwiEndpoint([$submission]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $notes = (string) Lead::where('external_ref', 'ff-22')->sole()->notes;
    foreach (['UPPER-1', 'ENC-1', 'ARR-1', 'FRAG-1', 'MSG-1', 'MSG-2'] as $secret) {
        expect($notes)->not->toContain($secret);
    }
    expect($notes)->toContain('Wchodzilem z https://kiwwwi.pl/?x=1 i mam kod')
        ->toContain("\nDzieki!")
        ->toContain('Source URL: https://kiwwwi.pl/kontakt/?utm_medium=cpc')
        ->toContain('Tracking: utm_medium=cpc');
});

test('an upper-case GCLID key still marks the lead as ads', function () {
    $submission = adsSubmission(23, null);
    $submission['source_url'] = 'https://kiwwwi.pl/?GCLID=UPPER-2';
    $submission['utm'] = ['GCLID' => 'UPPER-2'];
    fakeKiwwwiEndpoint([$submission]);

    $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

    $lead = Lead::where('external_ref', 'ff-23')->sole();
    expect($lead->source)->toBe('ads')
        ->and($lead->notes)->not->toContain('UPPER-2');
});

test('ClickIdScrubber strips every key spelling and keeps unrelated URL content', function (string $url, string $expected) {
    expect(ClickIdScrubber::url($url))->toBe($expected)
        ->and(ClickIdScrubber::text('Source URL: '.$url))->toBe('Source URL: '.$expected);
})->with([
    'plain' => ['https://kiwwwi.pl/a?gclid=S', 'https://kiwwwi.pl/a'],
    'upper case' => ['https://kiwwwi.pl/a?x=1&GCLID=S&y=2', 'https://kiwwwi.pl/a?x=1&y=2'],
    'percent-encoded key' => ['https://kiwwwi.pl/?%67clid=S&a=1', 'https://kiwwwi.pl/?a=1'],
    'double-encoded key' => ['https://kiwwwi.pl/?a=1&%2567clid=S', 'https://kiwwwi.pl/?a=1'],
    'array key' => ['https://kiwwwi.pl/?gclid[]=S&a=1', 'https://kiwwwi.pl/?a=1'],
    'encoded array key' => ['https://kiwwwi.pl/?gclid%5B%5D=S&a=1', 'https://kiwwwi.pl/?a=1'],
    'indexed array key' => ['https://kiwwwi.pl/?a=1&fbclid[0]=S', 'https://kiwwwi.pl/?a=1'],
    'fragment pair' => ['https://kiwwwi.pl/k/#gclid=S', 'https://kiwwwi.pl/k/'],
    'fragment pairs' => ['https://kiwwwi.pl/k/#a=1&msclkid=S', 'https://kiwwwi.pl/k/#a=1'],
    'hash route' => ['https://kiwwwi.pl/?a=1#/route?x=2&gbraid=S', 'https://kiwwwi.pl/?a=1#/route?x=2'],
    'anchor kept' => ['https://kiwwwi.pl/?utm_source=g&gclid=S&gad_source=1#form', 'https://kiwwwi.pl/?utm_source=g&gad_source=1#form'],
    'semicolon separator' => ['https://kiwwwi.pl/?a=1;wbraid=S;b=2', 'https://kiwwwi.pl/?a=1;b=2'],
    'html-escaped ampersand' => ['https://kiwwwi.pl/?a=1&amp;gclid=S', 'https://kiwwwi.pl/?a=1'],
    'nested encoded url' => ['https://kiwwwi.pl/?next=https%3A%2F%2Fx.pl%2F%3Fgclid%3DS%26b%3D2', 'https://kiwwwi.pl/?next=https%3A%2F%2Fx.pl%2F%3Fb%3D2'],
    'lookalike keys stay' => ['https://kiwwwi.pl/?xgclid=1&gclid_note=2&gad_source=1', 'https://kiwwwi.pl/?xgclid=1&gclid_note=2&gad_source=1'],
    'no query' => ['https://kiwwwi.pl/oferta/', 'https://kiwwwi.pl/oferta/'],
]);

test('ClickIdScrubber returns text without click ids byte for byte', function () {
    $text = "Cena=100 zl? Tak.\r\nhttps://kiwwwi.pl/?a=1&b=2#top\n\n  wciecie\r\nTracking: utm_source=x, gad_source=1\nkoniec\n";

    expect(ClickIdScrubber::text($text))->toBe($text)
        ->and(ClickIdScrubber::containsClickId($text))->toBeFalse()
        ->and(ClickIdScrubber::found($text))->toBe([]);
});

test('ClickIdScrubber catches bare, json and prose forms in free text', function () {
    $text = 'kod: GCLID=A1 oraz {"fbclid": "B2B2B2"} i msclkid: C3C3C3 zostaje utm_source=x';

    expect(ClickIdScrubber::text($text))->toBe('kod:  oraz {} i  zostaje utm_source=x')
        ->and(ClickIdScrubber::found($text))->toBe(['gclid', 'fbclid', 'msclkid'])
        ->and(ClickIdScrubber::text($text, ['gclid']))->toContain('GCLID=A1');
});

test('ClickIdScrubber leaves text without click ids alone and keeps blank lines', function () {
    $text = "Line one\n\nSee https://kiwwwi.pl/?utm_source=x&gclid=ABC and gbraid=XYZ\nTracking: utm_source=x, gclid=ABC";

    expect(ClickIdScrubber::text($text))
        ->toBe("Line one\n\nSee https://kiwwwi.pl/?utm_source=x and \nTracking: utm_source=x")
        ->and(ClickIdScrubber::containsClickId('no ids here'))->toBeFalse()
        ->and(ClickIdScrubber::url('https://kiwwwi.pl/a?gclid=1'))->toBe('https://kiwwwi.pl/a');
});

test('backfill is a dry run by default and strips click ids with --apply', function () {
    $unknown = Lead::create([
        'account_id' => $this->account->id,
        'pipeline' => 'kiwwwi',
        'name' => 'Old ads lead',
        'source' => 'ads',
        'external_ref' => 'ff-100',
        'notes' => "Prosze o kontakt.\nSource URL: https://kiwwwi.pl/?gad_source=1&gclid=OLD-GCLID\nTracking: gad_source=1, gclid=OLD-GCLID",
    ]);
    $trashed = Lead::create([
        'account_id' => $this->account->id,
        'pipeline' => 'kiwwwi',
        'name' => 'Deleted ads lead',
        'source' => 'ads',
        'external_ref' => 'ff-101',
        'notes' => 'Tracking: gbraid=OLD-GBRAID',
    ]);
    $trashed->delete();
    $granted = Lead::create([
        'account_id' => $this->account->id,
        'pipeline' => 'kiwwwi',
        'name' => 'Consented lead',
        'source' => 'ads',
        'marketing_consent' => 'granted',
        'external_ref' => 'ff-102',
        'notes' => 'Tracking: gclid=KEEP-ME',
    ]);
    $otherPipeline = Lead::create([
        'account_id' => $this->account->id,
        'pipeline' => 'filipchrapek',
        'name' => 'Other funnel',
        'source' => 'referral',
        'notes' => 'gclid=NOT-KIWWWI',
    ]);

    $this->artisan('kiwwwi:scrub-click-ids')->assertSuccessful();
    expect($unknown->fresh()->notes)->toContain('OLD-GCLID');

    $this->artisan('kiwwwi:scrub-click-ids --apply')->assertSuccessful();

    $unknown->refresh();
    expect($unknown->notes)->toBe("Prosze o kontakt.\nSource URL: https://kiwwwi.pl/?gad_source=1\nTracking: gad_source=1")
        ->and($unknown->source)->toBe('ads')
        ->and(Lead::withTrashed()->find($trashed->id)->notes)->toBeNull()
        ->and($granted->fresh()->notes)->toContain('KEEP-ME')
        ->and($otherPipeline->fresh()->notes)->toContain('NOT-KIWWWI');

    // Idempotent: nothing left on a second pass.
    $this->artisan('kiwwwi:scrub-click-ids --apply')
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

test('backfill detects every key spelling by comparing scrubbed notes, and trims granted leads to Google ids', function () {
    $make = fn (string $ref, string $notes, ?string $consent = null) => Lead::create([
        'account_id' => $this->account->id,
        'pipeline' => 'kiwwwi',
        'name' => 'Lead '.$ref,
        'source' => 'ads',
        'marketing_consent' => $consent,
        'external_ref' => $ref,
        'notes' => $notes,
    ]);

    $encoded = $make('ff-200', "Zapytanie.\nSource URL: https://kiwwwi.pl/?%67clid=ENC&utm_source=google");
    $upper = $make('ff-201', 'Source URL: https://kiwwwi.pl/?GCLID=UPPER&a=1');
    $array = $make('ff-202', 'Source URL: https://kiwwwi.pl/?gclid%5B%5D=ARR');
    $fragment = $make('ff-203', 'Source URL: https://kiwwwi.pl/kontakt/#gbraid=FRAG');
    $message = $make('ff-204', "Mam link https://kiwwwi.pl/?x=1&wbraid=MSG\nMarketing consent: unknown");
    $granted = $make('ff-205', 'Tracking: gclid=KEEP, fbclid=DROP-FB, msclkid=DROP-MS', 'granted');
    $clean = $make('ff-206', "Bez identyfikatorow.\r\nSource URL: https://kiwwwi.pl/?utm_source=x#form");

    $this->artisan('kiwwwi:scrub-click-ids')
        ->expectsOutputToContain('Dry run: 6 lead(s) would change')
        ->assertSuccessful();
    expect($encoded->fresh()->notes)->toContain('ENC');

    $this->artisan('kiwwwi:scrub-click-ids --apply')->assertSuccessful();

    expect($encoded->fresh()->notes)->toBe("Zapytanie.\nSource URL: https://kiwwwi.pl/?utm_source=google")
        ->and($upper->fresh()->notes)->toBe('Source URL: https://kiwwwi.pl/?a=1')
        ->and($array->fresh()->notes)->toBe('Source URL: https://kiwwwi.pl/')
        ->and($fragment->fresh()->notes)->toBe('Source URL: https://kiwwwi.pl/kontakt/')
        ->and($message->fresh()->notes)->toBe("Mam link https://kiwwwi.pl/?x=1\nMarketing consent: unknown")
        ->and($granted->fresh()->notes)->toBe('Tracking: gclid=KEEP')
        ->and($clean->fresh()->notes)->toBe("Bez identyfikatorow.\r\nSource URL: https://kiwwwi.pl/?utm_source=x#form");

    $this->artisan('kiwwwi:scrub-click-ids --apply')
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});
