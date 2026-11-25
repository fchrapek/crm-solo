<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Jobs\SendLeadIntakeNotice;
use App\Models\IntakeForm;
use App\Models\Lead;
use App\Models\LeadIntake;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores a form submission, the lead and its intake row in one transaction, or answers a replay of a key this form already took.
 *
 * @phpstan-type Fields array{name: string, email: string, message: string, company: ?string, lang: ?string, page_url: ?string, extras: array<string, string|list<string>>}
 */
final class FormIntake
{
    public const string CREATED = 'created';

    public const string REPLAY = 'replay';

    public const string CONFLICT = 'conflict';

    /** The note is CRM content, Polish whoever posts the form, until the CRM carries a content language. */
    private const string NOTE_LOCALE = 'pl';

    /** @param  Fields  $fields */
    public static function fingerprint(array $fields): string
    {
        ksort($fields);
        ksort($fields['extras']);

        return hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** REPLAY or CONFLICT for a key this form has already taken, null for a new one. */
    public function replay(IntakeForm $form, string $key, string $fingerprint): ?string
    {
        $existing = LeadIntake::query()->where('intake_form_id', $form->id)->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }

        return hash_equals($existing->fingerprint, $fingerprint) ? self::REPLAY : self::CONFLICT;
    }

    /** @param  Fields  $fields */
    public function record(IntakeForm $form, array $fields, string $key): string
    {
        $fingerprint = self::fingerprint($fields);
        if (($answer = $this->replay($form, $key, $fingerprint)) !== null) {
            return $answer;
        }

        try {
            $intake = DB::transaction(function () use ($form, $fields, $key, $fingerprint): LeadIntake {
                $lead = Lead::create([
                    'account_id' => $form->account_id,
                    'pipeline' => $form->pipeline,
                    'source' => $form->source,
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'company' => $fields['company'],
                    'external_ref' => "form-{$form->id}-{$key}",
                    'notes' => $this->notes($form, $fields),
                ]);

                return LeadIntake::create([
                    'account_id' => $form->account_id,
                    'intake_form_id' => $form->id,
                    'lead_id' => $lead->id,
                    'idempotency_key' => $key,
                    'fingerprint' => $fingerprint,
                    'notice_state' => LeadIntake::WAITING,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->replay($form, $key, $fingerprint) ?? throw $e;
        }

        // A notice the queue refuses stays waiting for lead-intake:notify-pending.
        SendLeadIntakeNotice::enqueue($intake->id);

        return self::CREATED;
    }

    /** @param  Fields  $fields */
    private function notes(IntakeForm $form, array $fields): string
    {
        $lines = [$fields['message'], ''];
        if ($fields['lang'] !== null) {
            $lines[] = __('Language: :language', ['language' => $fields['lang']], self::NOTE_LOCALE);
        }
        if ($fields['page_url'] !== null) {
            $lines[] = __('Page: :url', ['url' => $fields['page_url']], self::NOTE_LOCALE);
        }
        $lines[] = __('Form: :form', ['form' => $form->name], self::NOTE_LOCALE);
        if ($fields['extras'] !== []) {
            $lines[] = '';
            $lines[] = __('Other fields:', [], self::NOTE_LOCALE);
            foreach ($fields['extras'] as $key => $value) {
                $lines[] = $key.': '.(is_array($value) ? implode(', ', $value) : $value);
            }
            $lines[] = '';
        }
        $lines[] = __('Marketing consent: not collected', [], self::NOTE_LOCALE);

        return ClickIdScrubber::text(implode("\n", $lines), []);
    }
}
