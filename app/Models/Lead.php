<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * A lead in one of the two brand funnels (config/leadgen.php).
 *
 * Deliberately NOT a Client: clients carry NIP, Infakt ids and retainers, and
 * both funnels run entirely before anything becomes a client. `client_id` is
 * the conversion seam, set when a won lead is turned into one.
 *
 * The plan's vocabulary (pipelines, stages, sources) is never duplicated as
 * PHP enums — every rule below reads config('leadgen.*') so the marketing plan
 * and the code stay one contract.
 */
final class Lead extends Model
{
    use SoftDeletes;

    // Tier slugs are structural (they key config's tier_routing), so unlike
    // stages/sources they are code's own vocabulary, not the plan's list.
    public const TIER_GOLD = 'gold';

    public const TIER_OAK = 'oak';

    public const TIER_ROWAN = 'rowan';

    public const TIERS = [self::TIER_GOLD, self::TIER_OAK, self::TIER_ROWAN];

    protected $fillable = [
        'account_id',
        'pipeline',
        'name',
        'company',
        'email',
        'phone',
        'source',
        'stage',
        'score_factors',
        'client_id',
        'external_ref',
        'notes',
        'captured_at',
    ];

    /** @return array<int, string> */
    public static function pipelines(): array
    {
        return array_keys((array) config('leadgen.pipelines', []));
    }

    /** @return array<int, string> */
    public static function sources(): array
    {
        return (array) config('leadgen.sources', []);
    }

    /**
     * Stages a Lead row may actually hold: the pipeline's declared stages minus
     * the analytics-only head. kiwwwi's 'visitor' is funnel arithmetic — a
     * visitor is anonymous, so no row exists until they identify themselves,
     * and the visitor -> lead rate is measured in web analytics, not here.
     *
     * @return array<int, string>
     */
    public static function rowStages(string $pipeline): array
    {
        $config = self::pipelineConfig($pipeline);

        return array_values(array_diff($config['stages'], $config['analytics_only_stages'] ?? []));
    }

    /**
     * The one board vocabulary: ordered union of every pipeline's row stages.
     * Pipelines share the unified stage set by design (2026-07-28 rework), so
     * this normally equals any single pipeline's rowStages() — the union keeps
     * the board coherent even if a custom pipeline adds a stage.
     *
     * @return array<int, string>
     */
    public static function unifiedRowStages(): array
    {
        $stages = [];
        foreach (self::pipelines() as $pipeline) {
            foreach (self::rowStages($pipeline) as $stage) {
                if (! in_array($stage, $stages, true)) {
                    $stages[] = $stage;
                }
            }
        }

        return $stages;
    }

    /**
     * The stage a captured lead enters at: the source override if the pipeline
     * declares one (social DMs land straight in 'conversation'), else the
     * pipeline's declared entry stage. Never "stages[0]" — for kiwwwi that
     * would be the analytics-only 'visitor'.
     */
    public static function entryStage(string $pipeline, string $source): string
    {
        $config = self::pipelineConfig($pipeline);

        return $config['entry_stage_overrides'][$source] ?? $config['entry_stage'];
    }

    /** @return array<string, mixed> */
    public static function pipelineConfig(string $pipeline): array
    {
        $config = config("leadgen.pipelines.{$pipeline}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown lead pipeline [{$pipeline}].");
        }

        return $config;
    }

    /**
     * The stage that means "this one converted" — kiwwwi's 'won' and
     * filipchrapek's 'active_relationship'. Derived as the pipeline's last row
     * stage rather than listed here: the plan owns the vocabulary, and a
     * hardcoded pair would silently rot the day a funnel gains a stage.
     */
    public static function wonStage(string $pipeline): string
    {
        $stages = self::rowStages($pipeline);

        return (string) end($stages);
    }

    /**
     * The pipeline's scoring map: category => [factor slug => points].
     * kiwwwi scores FIT + BEHAVIOUR; filipchrapek adds TRIGGER.
     *
     * @return array<string, array<string, int>>
     */
    public static function scoringMap(string $pipeline): array
    {
        return (array) (self::pipelineConfig($pipeline)['scoring'] ?? []);
    }

    /**
     * Points for one factor in one pipeline, or null if the slug is not part
     * of that pipeline's map. Note: points can be negative — kiwwwi's
     * 'template-floor-signal' is -3, a lead actively signalling it wants the
     * cheap template floor.
     */
    public static function factorPoints(string $pipeline, string $category, string $factor): ?int
    {
        $points = self::scoringMap($pipeline)[$category][$factor] ?? null;

        return $points === null ? null : (int) $points;
    }

    /**
     * Total score: the sum of the ticked factors' configured points.
     *
     * Derived on every read, never stored. The plan re-tunes these weights
     * (Month-2 replaces assumed rates and re-runs the back-solve), and the
     * whole point of storing slugs is that yesterday's leads re-score under
     * today's weights automatically.
     */
    public function scoreTotal(): int
    {
        $total = 0;
        foreach ($this->scoreFactors() as $category => $factors) {
            foreach ($factors as $factor) {
                $total += self::factorPoints((string) $this->pipeline, $category, $factor) ?? 0;
            }
        }

        return $total;
    }

    /**
     * Gold / Oak / Rowan from the shared thresholds (Gold >= 7, Oak 4-6,
     * Rowan below). Tier drives routing, not just display: Gold earns a
     * personal reply inside 24h, Rowan gets no Filip-minutes at all.
     */
    public function tier(): string
    {
        $total = $this->scoreTotal();

        if ($total >= (int) config('leadgen.tiers.gold_min')) {
            return self::TIER_GOLD;
        }

        return $total >= (int) config('leadgen.tiers.oak_min') ? self::TIER_OAK : self::TIER_ROWAN;
    }

    /**
     * What the plan says to DO with this tier in this funnel — 'personal-reply'
     * vs 'value-first-audit' and so on. The tier is only useful because it
     * routes; surfacing the action keeps the score honest work rather than a
     * decorative badge.
     */
    public function tierRouting(): ?string
    {
        $routing = self::pipelineConfig((string) $this->pipeline)['tier_routing'] ?? [];

        return $routing[$this->tier()] ?? null;
    }

    /**
     * Ticked factors, normalised to category => list-of-slugs and filtered to
     * what this pipeline actually defines. Reading defensively means a factor
     * retired from the config stops counting instead of throwing on rows that
     * still mention it.
     *
     * @return array<string, array<int, string>>
     */
    public function scoreFactors(): array
    {
        $stored = $this->score_factors;
        if (! is_array($stored)) {
            return [];
        }

        $map = self::scoringMap((string) $this->pipeline);
        $clean = [];
        foreach ($map as $category => $factors) {
            $ticked = $stored[$category] ?? [];
            if (! is_array($ticked)) {
                continue;
            }
            $valid = array_values(array_intersect(array_map('strval', $ticked), array_keys($factors)));
            if ($valid !== []) {
                $clean[$category] = $valid;
            }
        }

        return $clean;
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->withTrashed()
            ->firstOrFail();
    }

    /**
     * Leads are not Scout-indexed (the funnel is small and lives behind a
     * pipeline filter), so search is a plain LIKE across the identifying
     * fields rather than a Typesense round-trip.
     *
     * @param  array<string, mixed>  $filters
     */
    #[Scope]
    public function filter(Builder $query, array $filters): void
    {
        $query
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search) => $query->where(function (Builder $q) use ($search): void {
                    $like = '%'.$search.'%';
                    $q->where('name', 'like', $like)
                        ->orWhere('company', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
            )
            ->when(
                ($filters['trashed'] ?? null) === 'with',
                fn (Builder $query) => $query->withTrashed()
            )
            ->when(
                ($filters['trashed'] ?? null) === 'only',
                fn (Builder $query) => $query->onlyTrashed()
            )
            ->when(
                ($filters['pipeline'] ?? null) && in_array($filters['pipeline'], self::pipelines(), true),
                fn (Builder $query) => $query->where('pipeline', $filters['pipeline'])
            )
            ->when(
                ($filters['source'] ?? null) && in_array($filters['source'], self::sources(), true),
                fn (Builder $query) => $query->where('source', $filters['source'])
            )
            ->when(
                ($filters['stage'] ?? null) && $filters['stage'] !== 'all',
                fn (Builder $query) => $query->where('stage', $filters['stage'])
            );
    }

    /** Won leads are the funnel's terminal stage — the convert-to-client seam. */
    public function isWon(): bool
    {
        return $this->stage === self::wonStage((string) $this->pipeline);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The client this lead became, once won and converted. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(LeadStageEvent::class);
    }

    /**
     * Move the lead to a new stage, recording an append-only event. Mirrors
     * Client::transitionTo(): single source of truth, one transaction, and a
     * same-stage call with a note is a valid timeline entry (returns null when
     * there is genuinely nothing to record).
     *
     * The capture event (null -> entry_stage) is written by booted() on create,
     * not here — a creation is not a transition.
     */
    public function transitionTo(string $toStage, ?string $note = null, ?User $user = null): ?LeadStageEvent
    {
        $toStage = mb_trim($toStage);
        $note = $note !== null ? mb_trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        self::assertRowStage($this->pipeline, $toStage);

        if ($this->stage === $toStage && $note === null) {
            return null;
        }

        return DB::transaction(function () use ($toStage, $note, $user): LeadStageEvent {
            $fromStage = $this->stage;

            if ($fromStage !== $toStage) {
                $this->stage = $toStage;
                $this->save();
            }

            return $this->stageEvents()->create([
                'account_id' => $this->account_id,
                'user_id' => $user?->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'note' => $note,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Guard the plan's hard rule at the model boundary so every write path —
     * controller, command, seeder, test — obeys it. `source` is set at capture
     * and never edited: "no source tag -> the channel doesn't exist", and an
     * editable tag would silently rewrite a channel's measured history.
     */
    protected static function booted(): void
    {
        self::creating(function (self $lead): void {
            $lead->pipeline = mb_trim((string) $lead->pipeline);
            $lead->source = mb_trim((string) $lead->source);

            self::assertPipeline($lead->pipeline);
            self::assertSource($lead->source);

            if ($lead->stage === null || $lead->stage === '') {
                $lead->stage = self::entryStage($lead->pipeline, $lead->source);
            }
            self::assertRowStage($lead->pipeline, (string) $lead->stage);
            self::assertScoreFactors($lead->pipeline, $lead->score_factors);

            $lead->captured_at ??= now();
        });

        // The capture event: from_stage is NULL because the lead was created at
        // this stage, not moved to it. Month-2 reads these rows to measure real
        // conversion — a synthetic "previous stage" here would invent a hop the
        // funnel never made and bias the very first rate computed.
        self::created(function (self $lead): void {
            $lead->stageEvents()->create([
                'account_id' => $lead->account_id,
                'user_id' => auth()->id(),
                'from_stage' => null,
                'to_stage' => $lead->stage,
                'note' => null,
                'created_at' => $lead->captured_at ?? now(),
            ]);
        });

        self::updating(function (self $lead): void {
            if ($lead->isDirty('source')) {
                throw new RuntimeException(
                    'Lead source is set at capture and cannot be changed — it is the channel attribution the plan measures.'
                );
            }
            if ($lead->isDirty('pipeline')) {
                throw new RuntimeException(
                    'Lead pipeline cannot be changed — the funnels have different stages; create a new lead instead.'
                );
            }
            if ($lead->isDirty('stage')) {
                self::assertRowStage($lead->pipeline, (string) $lead->stage);
            }
            if ($lead->isDirty('score_factors')) {
                self::assertScoreFactors((string) $lead->pipeline, $lead->score_factors);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'notes' => HumanizedText::class,
            'captured_at' => 'datetime',
            // Without this cast, saving the array throws "Array to string
            // conversion" (see CLAUDE.md pitfalls).
            'score_factors' => 'array',
        ];
    }

    /**
     * Reject factors the pipeline does not define. The funnels score on
     * different axes — kiwwwi has no TRIGGER category at all — so an outbound
     * factor on an inbound lead is a category error, not a harmless extra:
     * it would silently contribute 0 and make the score quietly wrong.
     */
    private static function assertScoreFactors(string $pipeline, mixed $factors): void
    {
        if ($factors === null) {
            return;
        }
        if (! is_array($factors)) {
            throw new InvalidArgumentException('Lead score factors must be a map of category => factor slugs.');
        }

        $map = self::scoringMap($pipeline);
        foreach ($factors as $category => $slugs) {
            if (! array_key_exists($category, $map)) {
                throw new InvalidArgumentException(
                    "Scoring category [{$category}] is not part of the [{$pipeline}] pipeline."
                );
            }
            if (! is_array($slugs)) {
                throw new InvalidArgumentException("Scoring category [{$category}] must hold a list of factor slugs.");
            }
            foreach ($slugs as $slug) {
                if (! array_key_exists((string) $slug, $map[$category])) {
                    throw new InvalidArgumentException(
                        "Scoring factor [{$slug}] is not part of the [{$pipeline}] pipeline's [{$category}] map."
                    );
                }
            }
        }
    }

    private static function assertPipeline(string $pipeline): void
    {
        if (! in_array($pipeline, self::pipelines(), true)) {
            throw new InvalidArgumentException("Unknown lead pipeline [{$pipeline}].");
        }
    }

    private static function assertSource(string $source): void
    {
        if (! in_array($source, self::sources(), true)) {
            throw new InvalidArgumentException("Unknown lead source [{$source}].");
        }
    }

    private static function assertRowStage(string $pipeline, string $stage): void
    {
        if (in_array($stage, self::pipelineConfig($pipeline)['analytics_only_stages'] ?? [], true)) {
            throw new InvalidArgumentException(
                "Stage [{$stage}] is funnel arithmetic only and cannot be held by a lead — it is measured in web analytics."
            );
        }

        if (! in_array($stage, self::rowStages($pipeline), true)) {
            throw new InvalidArgumentException("Stage [{$stage}] is not a stage of the [{$pipeline}] pipeline.");
        }
    }
}
