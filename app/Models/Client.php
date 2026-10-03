<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

final class Client extends Model
{
    use Concerns\Filterable, HasFactory, SoftDeletes;

    // Relationship states, not a funnel — the pre-client funnel lives in the
    // Leads pipeline (2026-07-28). Historical events may carry the retired
    // 'prospect' / 'offer_sent' slugs; rendering falls back to their labels.
    public const LIFECYCLE_STAGES = ['active', 'paused', 'churned'];

    public const SEGMENTS = ['agency', 'smb', 'enterprise', 'individual'];

    public const COOPERATION_TYPES = ['retainer', 'hourly', 'project', 'one_off'];

    public const MONTH_CLOSE_TYPES = ['maintenance', 'gig'];

    public const REPORT_MODES = ['report', 'summary_email', 'none'];

    protected $fillable = [
        'account_id',
        'type',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'region',
        'country',
        'postal_code',
        'tax_id',
        'business_type',
        'notes',
        'report_baseline_markdown',
        'external_ids',
        'is_pinned',
        'month_close_type',
        'include_in_month_close',
        'report_mode',
        'ssh_config',
        'maintenance_invoice_description',
        'lifecycle_stage',
        'segment',
        'cooperation_type',
        'currency',
        'hourly_rate',
    ];

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->withTrashed()
            ->firstOrFail();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The projects that are sites in the monthly close, in checklist order. A
     * client's site steps are seeded once per row here, so parking a site is a
     * flag flip rather than a deletion.
     */
    public function monthCloseSites(): HasMany
    {
        return $this->hasMany(Project::class)
            ->where('include_in_month_close', true)
            ->orderBy('name');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function retainers(): HasMany
    {
        return $this->hasMany(ClientRetainer::class)->orderByDesc('effective_from');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class)->orderByDesc('created_at');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ClientReport::class)->orderByDesc('period_start');
    }

    public function monthCloseRuns(): HasMany
    {
        return $this->hasMany(MonthCloseRun::class)->orderByDesc('period');
    }

    public function activeRetainerOn(\Illuminate\Support\Carbon $date): ?ClientRetainer
    {
        return $this->retainers()
            ->activeOn($date)
            ->where('is_active', true)
            ->reorder()
            ->orderByDesc('monthly_hours')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ClientRetainer>
     */
    public function activeRetainersOn(\Illuminate\Support\Carbon $date): \Illuminate\Support\Collection
    {
        return $this->retainers()
            ->activeOn($date)
            ->where('is_active', true)
            ->reorder()
            ->orderBy('invoice_group')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ClientLifecycleEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function latestLifecycleEvent(): HasOne
    {
        return $this->hasOne(ClientLifecycleEvent::class)->latestOfMany('created_at');
    }

    /** Invoices mirrored from Infakt, newest first. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('invoice_date');
    }

    /**
     * Atomically transitions the client to a new stage and writes an audit row.
     * Same-stage calls with a note are allowed (timeline-note entries).
     * Returns the created event, or null when the call is a true no-op
     * (same stage, no note).
     */
    public function transitionTo(string $toStage, ?string $note = null, ?User $user = null): ?ClientLifecycleEvent
    {
        $toStage = mb_trim($toStage);
        $note = $note !== null ? mb_trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        if ($this->lifecycle_stage === $toStage && $note === null) {
            return null;
        }

        return DB::transaction(function () use ($toStage, $note, $user): ClientLifecycleEvent {
            $fromStage = $this->lifecycle_stage;

            if ($fromStage !== $toStage) {
                $this->lifecycle_stage = $toStage;
                $this->save();
            }

            return $this->lifecycleEvents()->create([
                'account_id' => $this->account_id,
                'user_id' => $user?->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'note' => $note,
                'created_at' => now(),
            ]);
        });
    }

    #[Scope]
    public function filter(Builder $query, array $filters, int $accountId): void
    {
        $query
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->applySearchFilter($query, $search, $accountId, $filters['trashed'] ?? null))
            ->when($filters['trashed'] ?? null, fn ($query, $trashed) => $this->applyTrashedFilter($query, $trashed))
            ->when(
                ($filters['stage'] ?? null) && $filters['stage'] !== 'all',
                fn ($query) => $query->where('lifecycle_stage', $filters['stage'])
            )
            ->when(
                ($filters['month_close_type'] ?? null) && in_array($filters['month_close_type'], self::MONTH_CLOSE_TYPES, true),
                fn ($query) => $query->where('month_close_type', $filters['month_close_type'])
            );
    }

    /**
     * The client's private catch-all project, created as "General" when the
     * client has no private project. Every create path gets one through the
     * created hook; clients:ensure-general-project covers older rows.
     */
    public function ensureGeneralProject(): Project
    {
        $existing = $this->projects()->whereNull('trello_board_id')->orderBy('id')->first();

        return $existing ?? $this->projects()->create([
            'account_id' => $this->account_id,
            'name' => 'General',
        ]);
    }

    protected static function booted(): void
    {
        self::created(function (Client $client): void {
            $client->lifecycleEvents()->create([
                'account_id' => $client->account_id,
                'user_id' => auth()->id(),
                'from_stage' => null,
                'to_stage' => $client->lifecycle_stage ?? 'active',
                'note' => null,
                'created_at' => $client->created_at ?? now(),
            ]);

            // Time logged against the client needs a project to land on.
            $client->ensureGeneralProject();
        });
    }

    protected function casts(): array
    {
        return [
            'notes' => HumanizedText::class,
            'report_baseline_markdown' => HumanizedText::class,
            'maintenance_invoice_description' => HumanizedText::class,
            'external_ids' => 'array',
            'is_pinned' => 'boolean',
            'include_in_month_close' => 'boolean',
        ];
    }

    /** @return array<int, string> */
    protected function searchableLikeColumns(): array
    {
        return ['name', 'email', 'phone'];
    }
}
