<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Account extends Model
{
    use HasFactory;

    /** Modules the account can switch off; one missing from settings.features is on. */
    public const array FEATURES = ['billing'];

    protected $fillable = [
        'name',
        'is_test',
        'settings',
    ];

    /** The instance operator's account: the first one created, owner of the server's own configuration (Horizon, env integrations). */
    public static function operatorId(): ?int
    {
        $id = self::query()->min('id');

        return $id === null ? null : (int) $id;
    }

    public function isOperator(): bool
    {
        return $this->id === self::operatorId();
    }

    public function hasFeature(string $feature): bool
    {
        return ($this->settings['features'][$feature] ?? true) !== false;
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return array_combine(self::FEATURES, array_map($this->hasFeature(...), self::FEATURES));
    }

    /** The first month (YYYY-MM) whose retainer months must be settled; an account without the setting starts in the local month it was created. */
    public function settlementFrom(): string
    {
        $value = $this->settings['settlement_from'] ?? null;
        if (is_string($value) && LocalCalendar::isMonth($value)) {
            return $value;
        }

        return CarbonImmutable::instance($this->created_at ?? now())->setTimezone(LocalCalendar::timezone())->format('Y-m');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function clientFieldDefinitions(): HasMany
    {
        return $this->hasMany(ClientFieldDefinition::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'settings' => 'array',
        ];
    }
}
