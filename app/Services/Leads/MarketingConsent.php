<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Reads the consent snapshot the kiwwwi lead API attaches to a submission
 * (`consent`, recorded from the ConsentLite `cc_cookie` at submit time) and
 * answers, per ad service, one question: did this visitor grant it?
 *
 * A click id is kept only when the visitor granted the service that issued
 * it; denied and unknown both strip it. The channel attribution
 * (`source = ads`) is derived before stripping and stays.
 *
 * A service is GRANTED only when all of this holds:
 *  - the snapshot passes the strict schema check (exact version, exact slug
 *    values, list shapes, matching banner revision). Nothing is normalised:
 *    "mark eting" is malformed, not "marketing";
 *  - 'marketing' is among the accepted categories;
 *  - the service id is listed in the accepted services for 'marketing'.
 *
 * Why an empty or missing services list never grants: ConsentLite builds the
 * marketing category only from the services it detected on the page
 * (widget.ts, detectedByCategory), so a marketing category without services
 * does not exist in that widget. On kiwwwi.pl the only marketing service is
 * google_ads (kiwwwi-analytics-config.php, blog 1). An accepted category with
 * an empty list therefore means the service was not accepted (DENIED), and a
 * cookie with no services map for the category is no evidence (UNKNOWN).
 *
 * No cookie at submit is UNKNOWN, not DENIED: an expired or blocked cookie
 * looks the same as a visitor who never answered. It still strips.
 */
final class MarketingConsent
{
    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    /** Stored as NULL on the lead. */
    public const UNKNOWN = 'unknown';

    /** Snapshot shape the site sends (kiwwwi-lead-api.php CONSENT_RECORD_VERSION). */
    public const SCHEMA_VERSION = 2;

    public const CATEGORY = 'marketing';

    /** ConsentLite service id for Google Ads; its state is what leads.marketing_consent records. */
    public const GOOGLE_ADS = 'google_ads';

    /**
     * The ConsentLite service that has to be granted for each click id to
     * stay. Null = the widget has no service for that vendor, so the id can
     * never be consented to and always goes.
     */
    public const CLICK_ID_SERVICES = [
        'gclid' => self::GOOGLE_ADS,
        'gbraid' => self::GOOGLE_ADS,
        'wbraid' => self::GOOGLE_ADS,
        'dclid' => self::GOOGLE_ADS,
        'fbclid' => 'meta_pixel',
        'msclkid' => null,
    ];

    private const SLUG = '/\A[a-z][a-z0-9_]{0,63}\z/';

    private const MAX_ITEMS = 20;

    /** Google Ads state, for leads.marketing_consent and the notes line. */
    public static function fromSnapshot(mixed $snapshot): string
    {
        return self::serviceStatus($snapshot, self::GOOGLE_ADS);
    }

    public static function serviceStatus(mixed $snapshot, string $service): string
    {
        $parsed = self::parse($snapshot);
        if ($parsed === null || $parsed['cookie_present'] === false) {
            return self::UNKNOWN;
        }

        if (! in_array(self::CATEGORY, $parsed['categories'], true)) {
            return self::DENIED;
        }

        if (! array_key_exists(self::CATEGORY, $parsed['services'])) {
            return self::UNKNOWN;
        }

        return in_array($service, $parsed['services'][self::CATEGORY], true) ? self::GRANTED : self::DENIED;
    }

    /**
     * Click-id params the lead may keep: those whose issuing service was
     * granted. Everything else in ClickIdScrubber::PARAMS goes.
     *
     * @return list<string>
     */
    public static function keptClickIds(mixed $snapshot): array
    {
        $kept = [];
        foreach (self::CLICK_ID_SERVICES as $param => $service) {
            if ($service !== null && self::serviceStatus($snapshot, $service) === self::GRANTED) {
                $kept[] = $param;
            }
        }

        return $kept;
    }

    /**
     * Click ids a stored lead may keep, from its leads.marketing_consent
     * value alone (the backfill has no snapshot). Only Google ids can
     * survive: the column records the Google Ads state and nothing else.
     *
     * @return list<string>
     */
    public static function keptClickIdsForColumn(?string $column): array
    {
        if ($column !== self::GRANTED) {
            return [];
        }

        return array_keys(array_filter(
            self::CLICK_ID_SERVICES,
            fn (?string $service): bool => $service === self::GOOGLE_ADS
        ));
    }

    /** True when the site saw no consent cookie at submit. */
    public static function cookieAbsent(mixed $snapshot): bool
    {
        return (self::parse($snapshot)['cookie_present'] ?? null) === false;
    }

    /** Value for leads.marketing_consent: unknown is NULL. */
    public static function toColumn(string $status): ?string
    {
        return $status === self::UNKNOWN ? null : $status;
    }

    /**
     * Strict schema check. Returns null for anything that is not exactly the
     * shape the site writes; no value is trimmed, lower-cased or repaired.
     *
     * @return array{cookie_present: bool, categories: list<string>, services: array<string, list<string>>}|null
     */
    public static function parse(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)
            || ($snapshot['v'] ?? null) !== self::SCHEMA_VERSION
            || ! is_bool($snapshot['cookie_present'] ?? null)
            || ! is_bool($snapshot['valid'] ?? null)) {
            return null;
        }

        if ($snapshot['cookie_present'] === false) {
            return $snapshot['valid'] === false
                ? ['cookie_present' => false, 'categories' => [], 'services' => []]
                : null;
        }

        if ($snapshot['valid'] !== true) {
            return null;
        }

        $categories = self::slugList($snapshot['categories'] ?? null);
        // vanilla-cookieconsent always stores the read-only 'necessary'
        // category; a cookie without it did not come from the widget.
        if ($categories === null || ! in_array('necessary', $categories, true)) {
            return null;
        }

        $rawServices = $snapshot['services'] ?? null;
        if (! is_array($rawServices) || count($rawServices) > self::MAX_ITEMS) {
            return null;
        }

        $services = [];
        foreach ($rawServices as $category => $list) {
            if (! is_string($category) || preg_match(self::SLUG, $category) !== 1) {
                return null;
            }
            $list = self::slugList($list);
            if ($list === null) {
                return null;
            }
            $services[$category] = $list;
        }

        $revision = $snapshot['revision'] ?? null;
        if (! is_int($revision) || $revision !== self::expectedRevision()) {
            return null;
        }

        return ['cookie_present' => true, 'categories' => $categories, 'services' => $services];
    }

    /**
     * The banner revision consent is valid for. ConsentLite leaves the
     * vanilla-cookieconsent default (0); bump KIWWWI_CONSENT_REVISION when the
     * banner's revision changes so stale cookies stop counting.
     */
    private static function expectedRevision(): int
    {
        return (int) config('services.kiwwwi.leads.consent_revision', 0);
    }

    /**
     * @return list<string>|null
     */
    private static function slugList(mixed $list): ?array
    {
        if (! is_array($list) || ! array_is_list($list) || count($list) > self::MAX_ITEMS) {
            return null;
        }

        foreach ($list as $item) {
            if (! is_string($item) || preg_match(self::SLUG, $item) !== 1) {
                return null;
            }
        }

        return $list;
    }
}
