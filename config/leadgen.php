<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lead-gen contract
|--------------------------------------------------------------------------
|
| Single source of truth for the lead funnel. Everything the Lead feature
| validates against (source taxonomy, stages, scoring factors, tier
| thresholds, follow-up SLAs) lives here, so the vocabulary cannot drift
| between the code and whatever plan the funnel is measured against.
|
| Pipelines are brand funnels. They share one stage vocabulary but keep
| their own scoring and routing, because different brands sell differently.
| A pipeline slug is permanent attribution on every lead captured under it,
| so rename the `label` rather than the key. Labels, custom sources and
| source labels can also be overridden per account from Settings (settings
| table, scope 'leadgen', merged over these defaults in AppServiceProvider).
|
| The numbers below (assumed conversion rates, targets, SLAs, thresholds)
| are illustrative defaults. Replace them with your own measured rates once
| you have enough logged leads; LeadStageEvent timestamps are what the
| measurement reads.
|
| One rule the code enforces: source is set at capture and never edited
| afterwards. No source tag means the channel does not exist, and an
| editable tag would rewrite a channel's measured history.
*/

return [

    /*
    | Source taxonomy — minimal defaults, set at capture, immutable afterwards.
    | Custom sources append via Settings; historical rows may carry retired
    | slugs, which still render through the translation/humanize chain.
    */
    'sources' => [
        'www-form',
        'ads',
        'social',
        'referral',
        'outbound',
        'other',
    ],

    /*
    | Optional display labels, slug => label. The UI resolves every slug
    | through one chain: explicit label here -> app translation
    | (lang/en.json + lang/pl.json) -> the humanized slug itself, so a slug
    | added here or via Settings never renders raw.
    */
    'source_labels' => [],

    /*
    | Brand funnels. One shared stage vocabulary (plain language, no MQL/SQL
    | jargon); per-brand scoring and tier routing stay separate because the
    | brands sell differently. Two are declared here as a worked shape: an
    | inbound funnel fed by a website and ads, and an outbound funnel fed by
    | direct approaches. Rename the labels, keep or replace the keys.
    |
    | 'assumed_rates' feed a back-solve ("how many leads for N wins?") until
    | there are enough LeadStageEvent rows to measure the real ones. The
    | values shipped here are placeholders, not observed rates.
    |
    | Each pipeline accepts the same optional label maps as 'source_labels':
    | 'stage_labels', 'factor_labels', 'category_labels', 'routing_labels'.
    */
    'pipelines' => [

        'kiwwwi' => [
            'label' => 'Kiwwwi',
            'stages' => ['visitor', 'new', 'conversation', 'offer', 'won'],
            // Stage a captured lead enters at, unless the source overrides it.
            // NOT the first stage: 'visitor' is funnel math only (see below).
            'entry_stage' => 'new',
            // Stages that exist for funnel arithmetic but can never be a Lead
            // row's stage. A visitor is anonymous until they submit the form,
            // so the visitor -> lead conversion is measured in web analytics,
            // never in the CRM. Seeding rows at 'visitor' would forge a 100%
            // rate at the one boundary the CRM cannot observe, and poison the
            // swap to measured rates later.
            'analytics_only_stages' => ['visitor'],
            // Placeholders. Replace with rates measured from LeadStageEvent.
            'assumed_rates' => [
                'won_from_offer' => 0.50,
                'offer_from_conversation' => 0.50,
                'conversation_from_new' => 0.50,
            ],
            // What the funnel is being sized against, over the planning
            // horizon. Illustrative.
            'targets' => [
                'won_builds_6mo' => 5,
                'leads_6mo' => 40,
            ],
            'scoring' => [
                'fit' => [
                    'industry-ecommerce-services' => 2,
                    'has-existing-site' => 2,
                    'budget-signal-5k' => 3,
                    'template-floor-signal' => -3,
                ],
                'behaviour' => [
                    'magnet-download' => 2,
                    'case-study-view' => 1,
                    'pricing-page-view' => 2,
                    'repeat-visit' => 1,
                ],
            ],
            // Tier decides what happens next, not just what colour the badge
            // is. Hot earns a person's time, cold earns none.
            'tier_routing' => [
                'gold' => 'personal-reply',
                'oak' => 'nurture',
                'rowan' => 'self-serve',
            ],
        ],

        'filipchrapek' => [
            'label' => 'filipchrapek',
            'stages' => ['new', 'conversation', 'offer', 'won'],
            'entry_stage' => 'new',
            'analytics_only_stages' => [],
            // Placeholders. An outbound funnel converts far lower at the top
            // than an inbound one, because a touch is not a request.
            'assumed_rates' => [
                'won_from_offer' => 0.50,
                'offer_from_conversation' => 0.50,
                'conversation_from_new' => 0.10,
            ],
            // Illustrative. Outbound is usually sized on activity (touches)
            // rather than on arrivals, since nobody arrives on their own.
            'targets' => [
                'active_relationships_added_6mo' => 4,
                'touches_per_week' => 10,
            ],
            'scoring' => [
                'fit' => [
                    'agency-10-50-people' => 3,
                    'wp-woo-stack' => 3,
                    'hires-senior-roles' => 2,
                ],
                'trigger' => [
                    'senior-dev-job-ad' => 3,
                    'funding' => 2,
                    'replatform-signal' => 3,
                    'new-cto-cmo' => 2,
                ],
                'behaviour' => [
                    'profile-view-or-post-engagement' => 1,
                    'case-study-visit' => 2,
                ],
            ],
            // Hot here means both a fit signal and a timing trigger, which
            // earns real preparation rather than another message.
            'tier_routing' => [
                'gold' => 'value-first-audit',
                'oak' => 'tivc-message',
                'rowan' => 'follow-list',
            ],
            // Sources that skip the top of the funnel: an inbound social DM
            // is already a conversation, so it enters as one.
            'entry_stage_overrides' => [
                'social' => 'conversation',
            ],
        ],

    ],

    /*
    | Score tiers (shared): total = FIT + BEHAVIOUR + TRIGGER points.
    | Hot (gold) at or above gold_min, Warm (oak) between the two, Cold
    | (rowan) below. The slugs stay gold/oak/rowan: they key tier_routing and
    | Lead::TIERS, while the labels the user sees resolve through i18n.
    */
    'tiers' => [
        'gold_min' => 7,
        'oak_min' => 4,
    ],

    /*
    | Follow-up mechanics. Cadence is days after an offer or last touch; the
    | SLA applies to Hot-tier leads only, because promising a fast reply to
    | every lead is how the promise stops meaning anything.
    */
    'follow_up' => [
        'cadence_days' => [3, 7, 14],
        'sla' => [
            'gold_first_reply_hours' => 24,
            'gold_proposal_working_days' => 5,
        ],
    ],

    /*
    | Cost of acquisition, measured in time rather than spend: minutes
    | logged per lead at touch level, reported monthly as hours-to-won by
    | source. The point is to retire channels that eat hours without
    | producing clients.
    */
    'cac' => [
        'unit' => 'minutes',
        'logged_at' => 'touch',
        'monthly_report' => 'hours-to-won-by-source',
        'clv_cac_min_ratio' => 3,
    ],

    /*
    | Concentration monitor: share of revenue per client over a trailing
    | window, plus a tripwire on a sharp month-over-month drop in one
    | client's hours. For a solo practice, concentration is the risk that
    | actually ends businesses.
    */
    'concentration' => [
        'trailing_days' => 90,
        'metric' => 'revenue-share-per-client',
        'r1_tripwire' => [
            'metric' => 'client-monthly-hours',
            'max_drop_mom' => 0.50,
        ],
    ],

    /*
    | Once this many lead events are logged, there is enough signal to
    | replace assumed_rates with measured ones and re-run the back-solve.
    */
    'measured_rates_after_events' => 30,

];
