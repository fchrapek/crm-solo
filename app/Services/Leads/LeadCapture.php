<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Account;
use App\Models\Lead;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The shared capture for the crm verb, the MCP tool and the waitlist. The
 * Leads form and the Kiwwwi pull create leads directly; pipeline and source
 * validation, the entry stage and the capture event live in Lead::booted().
 */
final class LeadCapture
{
    /**
     * @param  array{name: string, source: string, pipeline?: ?string, email?: ?string, phone?: ?string, company?: ?string, notes?: ?string}  $attributes
     *
     * @throws InvalidArgumentException on an unknown pipeline or source
     */
    public function capture(Account $account, array $attributes): Lead
    {
        return Lead::create($this->row($account, $attributes));
    }

    /**
     * Capture unless the account already holds a lead from the same source
     * with the same email, ignoring case. Deleted leads count, so a second
     * submission never brings back a lead removed in the CRM. The unique
     * (account_id, capture_key) index settles two requests racing past the
     * lookup: the loser gets the winner's lead.
     *
     * @param  array{name: string, source: string, email: string, pipeline?: ?string, phone?: ?string, company?: ?string, notes?: ?string}  $attributes
     *
     * @throws InvalidArgumentException on an unknown pipeline or source
     */
    public function captureOnce(Account $account, array $attributes): Lead
    {
        $email = mb_strtolower(mb_trim($attributes['email']));
        $source = mb_trim($attributes['source']);
        $key = hash('sha256', $source."\n".$email);

        $existing = fn (): ?Lead => Lead::withTrashed()
            ->where('account_id', $account->id)
            ->where(fn ($query) => $query
                ->where('capture_key', $key)
                ->orWhere(fn ($query) => $query->where('source', $source)->whereRaw('LOWER(email) = ?', [$email])))
            ->first();

        if (($lead = $existing()) !== null) {
            return $lead;
        }

        try {
            return Lead::query()->getConnection()->transaction(fn (): Lead => Lead::create([
                ...$this->row($account, [...$attributes, 'email' => $email]),
                'capture_key' => $key,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return $existing() ?? throw $e;
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : mb_trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{name: string, source: string, pipeline?: ?string, email?: ?string, phone?: ?string, company?: ?string, notes?: ?string}  $attributes
     * @return array<string, mixed>
     */
    private function row(Account $account, array $attributes): array
    {
        $pipeline = self::blankToNull($attributes['pipeline'] ?? null);

        return [
            'account_id' => $account->id,
            'pipeline' => $pipeline ?? (Lead::pipelines()[0] ?? ''),
            'name' => mb_trim($attributes['name']),
            'source' => mb_trim($attributes['source']),
            'email' => self::blankToNull($attributes['email'] ?? null),
            'phone' => self::blankToNull($attributes['phone'] ?? null),
            'company' => self::blankToNull($attributes['company'] ?? null),
            'notes' => self::blankToNull($attributes['notes'] ?? null),
        ];
    }
}
