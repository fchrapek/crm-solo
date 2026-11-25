<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Jobs\SendSummaryEmail;
use App\Mail\ClientSummaryMail;
use App\Models\Client;
use App\Models\ClientSummaryEmail;
use App\Models\MessageTemplate;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\User;
use App\Services\Agent\AgentCallContext;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Clients\ClientFinancialLock;
use App\Support\LocalCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * A client's month-end summary email: one per client and month, made from an
 * email template, edited, then sent once by the owner's click. Agents make and
 * refresh drafts; nothing here lets an agent send.
 *
 * Every send is a numbered attempt. Claiming one bumps `attempt` in a single
 * guarded statement, and every later write for it is guarded by that attempt
 * and the state it expects, so a stale job or a second click changes nothing.
 *
 * | State       | Moved to    | By                                                               |
 * |-------------|-------------|------------------------------------------------------------------|
 * | (none)      | draft       | draft(): made from a template                                    |
 * | draft       | queued      | send(), the owner: content frozen, one job queued                |
 * | test_sent   | queued      | send(), the owner                                                |
 * | failed      | queued      | send(), the owner                                                |
 * | queued      | sent        | the job: live delivery accepted by the mailer (final, locked)    |
 * | queued      | test_sent   | the job: log, array or redirect delivery                         |
 * | queued      | failed      | the job: the mailer answered with a rejection, or sending is off |
 * | queued      | unconfirmed | the job: any other error after hand-off, or a hand-off seen twice|
 * | queued      | queued      | send(), the owner, once the lease ran out before any hand-off    |
 * | unconfirmed | queued      | send() with resendUnconfirmed, the owner, after checking         |
 * | queued lost | queued      | send() with resendUnconfirmed: handed off, never reported back   |
 * | unconfirmed | sent        | markSent(), the owner: found it in the mailbox (live only)       |
 *
 * draft, test_sent and failed are editable, and refreshing one returns it to
 * draft. A test send never locks the email and never ticks the month close.
 */
final class SummaryEmails
{
    public const string STEP = MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL;

    /** The error of a send that never reached the mailer; the screens translate it. */
    public const string NOT_STARTED = 'Sending did not start.';

    public function __construct(
        private readonly MessageRenderer $renderer,
        private readonly ClientFinancialLock $lock,
        private readonly MailDelivery $delivery,
        private readonly MonthCloseTicker $ticker,
    ) {}

    /** A subject is one line: any run of whitespace, line breaks included, becomes one space. */
    public static function oneLine(string $subject): string
    {
        return mb_substr(mb_trim((string) preg_replace('/\s+/u', ' ', $subject)), 0, 200);
    }

    /** The month's email, settled first where a send was lost or a tick never ran (recover()). */
    public function find(Client $client, string $period): ?ClientSummaryEmail
    {
        $email = ClientSummaryEmail::query()->where('client_id', $client->id)->where('period', $this->period($period))->first();

        return $email === null ? null : $this->recover($email);
    }

    /**
     * What a crashed worker left behind, settled on the next read: a send whose
     * lease ran out before it reached the mailer failed (nothing went out), one
     * that reached it is unconfirmed, and a live sent email whose step never
     * ticked is ticked now.
     */
    public function recover(ClientSummaryEmail $email): ClientSummaryEmail
    {
        // Each write is guarded on what it was decided from, so a worker handing off meanwhile is seen on the next pass.
        for ($pass = 0; $pass < 3 && $email->isLost(); $pass++) {
            $lost = ClientSummaryEmail::query()->whereKey($email->id)->where('attempt', $email->attempt)->where('status', ClientSummaryEmail::QUEUED)
                ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()));
            $email->handed_off_at === null
                ? $lost->whereNull('handed_off_at')->update(['status' => ClientSummaryEmail::FAILED, 'error' => self::NOT_STARTED, 'lease_expires_at' => null, 'updated_at' => now()])
                : $lost->whereNotNull('handed_off_at')->update(['status' => ClientSummaryEmail::UNCONFIRMED, 'lease_expires_at' => null, 'updated_at' => now()]);
            $email = ClientSummaryEmail::query()->findOrFail($email->id);
        }
        if ($email->status === ClientSummaryEmail::SENT && $email->delivery === MailDelivery::LIVE && $email->step_ticked_at === null) {
            $email = $this->tickStep($email);
        }

        return $email;
    }

    /**
     * Makes the month's draft, or fills an editable one again from its template;
     * recipients stay. A subject or text edited by hand stays unless $replaceEdits.
     */
    public function draft(Client $client, ?string $period = null, ?string $templateKey = null, bool $replaceEdits = false): ClientSummaryEmail
    {
        $this->assertBilling($client);
        $period = $this->period($period ?? LocalCalendar::previousMonth());

        try {
            return $this->lock->client($client->id, fn (Client $locked): ClientSummaryEmail => $this->draftLocked($locked, $period, $templateKey, $replaceEdits));
        } catch (UniqueConstraintViolationException) {
            return $this->lock->client($client->id, fn (Client $locked): ClientSummaryEmail => $this->draftLocked($locked, $period, $templateKey, $replaceEdits));
        }
    }

    /**
     * @param  array{subject?: string, body?: string, recipients?: list<string>, bcc_owner?: bool}  $changes
     */
    public function update(ClientSummaryEmail $email, array $changes): ClientSummaryEmail
    {
        return $this->lock->client($email->client_id, function (Client $client) use ($email, $changes): ClientSummaryEmail {
            $locked = $this->lockedRow($email);
            $this->assertEditable($locked);

            if (array_key_exists('recipients', $changes)) {
                $locked->recipients = $this->checkedRecipients($client, $changes['recipients']);
            }
            $text = [(string) $locked->subject, (string) $locked->body];
            if (array_key_exists('subject', $changes)) {
                $subject = self::oneLine($changes['subject']);
                if ($subject === '') {
                    throw AgentRefusal::invalid('The email needs a subject.');
                }
                $locked->subject = $subject;
            }
            if (array_key_exists('body', $changes)) {
                if (mb_trim($changes['body']) === '') {
                    throw AgentRefusal::invalid('The email needs a text.');
                }
                $locked->body = mb_rtrim($changes['body'])."\n";
            }
            if ([(string) $locked->subject, (string) $locked->body] !== $text) {
                $locked->text_edited_at = now();
            }
            if (array_key_exists('bcc_owner', $changes)) {
                $locked->bcc_owner = $changes['bcc_owner'];
            }
            $locked->status = ClientSummaryEmail::DRAFT;
            $locked->error = null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * The owner's send: the content as it is now is what the job sends.
     */
    public function send(ClientSummaryEmail $email, User $owner, bool $resendUnconfirmed = false): ClientSummaryEmail
    {
        if (! $owner->owner || (int) $owner->account_id !== (int) $email->account_id) {
            throw new AgentRefusal(AgentRefusal::REFUSED, 'Only the account owner sends a summary email.');
        }
        if ($this->delivery->mode() === MailDelivery::REFUSED) {
            throw new AgentRefusal(AgentRefusal::REFUSED, $this->delivery->refusal());
        }

        [$id, $attempt] = $this->lock->client($email->client_id, function (Client $client) use ($email, $owner, $resendUnconfirmed): array {
            $this->assertBilling($client);
            $locked = $this->lockedRow($email);
            $this->assertSendable($locked, $resendUnconfirmed);
            $recipients = $this->checkedRecipients($client, (array) $locked->recipients);
            if ($recipients === []) {
                throw AgentRefusal::invalid('Pick at least one recipient first.');
            }

            $claimed = ClientSummaryEmail::query()
                ->whereKey($locked->id)
                ->where('attempt', $locked->attempt)
                ->where('status', $locked->status)
                ->update([
                    'status' => ClientSummaryEmail::QUEUED,
                    'attempt' => $locked->attempt + 1,
                    'recipients' => json_encode($recipients),
                    'bcc_address' => $locked->bcc_owner ? $owner->email : null,
                    'lease_expires_at' => now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES),
                    'handed_off_at' => null,
                    'delivery' => null,
                    'sent_at' => null,
                    'sent_by_user_id' => $owner->id,
                    'message_id' => null,
                    'error' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed !== 1) {
                throw new AgentRefusal(AgentRefusal::REFUSED, 'Another send claimed this email first.');
            }

            return [$locked->id, $locked->attempt + 1];
        });

        SendSummaryEmail::dispatch($id, $attempt)->afterCommit();

        return ClientSummaryEmail::query()->findOrFail($id);
    }

    /** Run by the queued job for one claimed attempt; a stale attempt does nothing. */
    public function deliver(int $id, int $attempt): void
    {
        $email = ClientSummaryEmail::query()->find($id);
        if ($email === null || $email->attempt !== $attempt || $email->status !== ClientSummaryEmail::QUEUED) {
            return;
        }
        if ($email->handed_off_at !== null) {
            $this->move($id, $attempt, ['status' => ClientSummaryEmail::UNCONFIRMED, 'error' => 'The send ran a second time after it had reached the mailer; check the mailbox.']);

            return;
        }

        $mode = $this->delivery->mode();
        if ($mode === MailDelivery::REFUSED) {
            $this->move($id, $attempt, ['status' => ClientSummaryEmail::FAILED, 'error' => $this->delivery->refusal()]);

            return;
        }

        // Only inside the lease: past it, recovery may already be deciding the send never started.
        $handed = ClientSummaryEmail::query()->whereKey($id)->where('attempt', $attempt)->where('status', ClientSummaryEmail::QUEUED)->whereNull('handed_off_at')
            ->where('lease_expires_at', '>', now())
            ->update(['handed_off_at' => now(), 'delivery' => $mode, 'updated_at' => now()]);
        if ($handed !== 1) {
            return;
        }

        try {
            $sent = Mail::mailer()->send(new ClientSummaryMail(array_values((array) $email->recipients), $email->bcc_address, (string) $email->subject, (string) $email->body));
        } catch (UnexpectedResponseException $e) {
            $this->move($id, $attempt, ['status' => ClientSummaryEmail::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            return;
        } catch (Throwable $e) {
            $this->move($id, $attempt, ['status' => ClientSummaryEmail::UNCONFIRMED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            return;
        }

        $live = $mode === MailDelivery::LIVE;
        $moved = $this->move($id, $attempt, [
            'status' => $live ? ClientSummaryEmail::SENT : ClientSummaryEmail::TEST_SENT,
            'delivery' => $mode,
            'sent_at' => now(),
            'message_id' => $sent?->getMessageId(),
            'lease_expires_at' => null,
        ]);

        if ($live && $moved) {
            $this->tickStep(ClientSummaryEmail::query()->findOrFail($id));
        }
    }

    /**
     * The owner found an unconfirmed live email in the mailbox: it is recorded
     * as sent by them, now, and the step ticks. A test attempt reached no client.
     */
    public function markSent(ClientSummaryEmail $email, User $owner): ClientSummaryEmail
    {
        if (! $owner->owner || (int) $owner->account_id !== (int) $email->account_id) {
            throw new AgentRefusal(AgentRefusal::REFUSED, 'Only the account owner sends a summary email.');
        }

        $marked = $this->lock->client($email->client_id, function () use ($email, $owner): ClientSummaryEmail {
            $locked = $this->lockedRow($email);
            $unknown = $locked->status === ClientSummaryEmail::UNCONFIRMED || ($locked->isLost() && $locked->handed_off_at !== null);
            if (! $unknown || $locked->delivery !== MailDelivery::LIVE) {
                throw AgentRefusal::invalid('Only an email whose live send is unconfirmed can be marked as sent.');
            }
            $locked->forceFill([
                'status' => ClientSummaryEmail::SENT,
                'sent_at' => now(),
                'sent_by_user_id' => $owner->id,
                'lease_expires_at' => null,
                'attempt' => $locked->attempt + 1,
            ])->save();

            return $locked;
        });

        return $this->tickStep($marked);
    }

    /**
     * Ticks the month-close step for a live sent email, apart from the delivery:
     * a tick that fails is recorded and tried again, the email stays sent.
     */
    public function tickStep(ClientSummaryEmail $email): ClientSummaryEmail
    {
        if ($email->status !== ClientSummaryEmail::SENT || $email->delivery !== MailDelivery::LIVE) {
            throw AgentRefusal::invalid('Only an email sent to the client ticks the month close.');
        }

        // Client row, then the email row, re-read: two readers at once tick once.
        return $this->lock->client($email->client_id, function () use ($email): ClientSummaryEmail {
            $locked = $this->lockedRow($email);
            if ($locked->step_ticked_at !== null) {
                return $locked;
            }

            try {
                $this->ticker->tick($locked->client, $locked->period, self::STEP, MonthCloseStep::STATE_DONE, $locked->sentBy, null, null, MonthCloseStep::VIA_WEB);
                $locked->forceFill(['step_ticked_at' => now(), 'tick_error' => null])->save();
            } catch (Throwable $e) {
                $locked->forceFill(['tick_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            }

            return $locked;
        });
    }

    /**
     * The addresses a summary email may go to: its contacts', then the client's own.
     *
     * @return list<array{email: string, name: string, source: 'contact'|'client'}>
     */
    public function candidates(Client $client): array
    {
        $found = [];
        $add = function (?string $email, string $name, string $source) use (&$found): void {
            $email = mb_strtolower(mb_trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! isset($found[$email])) {
                $found[$email] = ['email' => $email, 'name' => $name, 'source' => $source];
            }
        };

        foreach ($client->contacts()->orderBy('id')->get() as $contact) {
            foreach ((array) $contact->emails as $email) {
                $add(is_string($email) ? $email : null, mb_trim($contact->first_name.' '.$contact->last_name), 'contact');
            }
        }
        $add($client->email, $client->name, 'client');

        return array_values($found);
    }

    /**
     * Each month's email state for the client's Billing, keyed by period; null when the client sends
     * no summary emails or the viewer is not the owner, so the ledger keeps its report column.
     *
     * @return array<string, array{status: ?string, drafted_on: ?string, drafted_via: ?string, sent_on: ?string, changed_on: ?string, recipients: int}>|null
     */
    public function monthsFor(Client $client, ?User $viewer): ?array
    {
        if ($client->report_mode !== MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL || $viewer === null || ! $viewer->owner) {
            return null;
        }

        return ClientSummaryEmail::query()->where('client_id', $client->id)->orderByDesc('period')->get()
            ->mapWithKeys(fn (ClientSummaryEmail $email): array => [$email->period => $this->state($this->recover($email))])
            ->all();
    }

    /**
     * What a month-close row or a ledger cell says about the month's email, dates as local days.
     *
     * @return array{status: ?string, drafted_on: ?string, drafted_via: ?string, sent_on: ?string, changed_on: ?string, recipients: int}
     */
    public function state(?ClientSummaryEmail $email): array
    {
        $day = fn ($at): ?string => $at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString();

        return [
            'status' => $email?->status,
            'drafted_on' => $day($email?->drafted_at),
            'drafted_via' => $email?->drafted_via,
            'sent_on' => $day($email?->sent_at),
            'changed_on' => $day($email?->updated_at),
            'recipients' => count((array) $email?->recipients),
        ];
    }

    /** @return array<string, mixed> */
    public function present(ClientSummaryEmail $email, bool $withCandidates = false): array
    {
        $email->loadMissing(['client', 'template', 'sentBy']);
        $local = fn ($at): ?array => $at === null ? null : ['day' => $at->copy()->setTimezone(LocalCalendar::timezone())->toDateString(), 'time' => $at->copy()->setTimezone(LocalCalendar::timezone())->format('H:i')];

        return [
            'id' => $email->id,
            'client_id' => $email->client_id,
            'client' => $email->client?->name,
            'period' => $email->period,
            'status' => $email->status,
            'delivery' => $email->delivery,
            'template' => $email->template === null ? null : ['key' => $email->template->key, 'name' => $email->template->name],
            'recipients' => array_values((array) $email->recipients),
            'bcc_owner' => $email->bcc_owner,
            'subject' => (string) $email->subject,
            'body' => (string) $email->body,
            'drafted' => $local($email->drafted_at),
            'drafted_via' => $email->drafted_via,
            'changed' => $local($email->updated_at),
            'attempt' => $email->attempt,
            'sent' => $local($email->sent_at),
            'sent_by' => $email->sentBy?->name,
            'message_id' => $email->message_id,
            'error' => $email->error,
            'step_ticked' => $email->step_ticked_at !== null,
            'tick_error' => $email->tick_error,
            'text_edited' => $email->text_edited_at !== null,
            'editable' => $email->isEditable(),
            'locked' => $email->status === ClientSummaryEmail::SENT,
            'lost' => $email->isLost(),
            ...($withCandidates ? ['candidates' => $this->candidates($email->client)] : []),
        ];
    }

    private function draftLocked(Client $client, string $period, ?string $templateKey, bool $replaceEdits): ClientSummaryEmail
    {
        $email = ClientSummaryEmail::query()->where('client_id', $client->id)->where('period', $period)->lockForUpdate()->first();
        if ($email !== null) {
            $this->assertEditable($email);
            if ($email->text_edited_at !== null && ! $replaceEdits) {
                throw new AgentRefusal(AgentRefusal::REFUSED, 'The subject or text of this draft was edited by hand, so it stays. The owner refreshes it from the template in the app.', ['field' => 'template']);
            }
        }

        $template = $this->template($client, $period, $templateKey, $email);
        $fields = $this->renderer->fields($client->account_id);
        try {
            $subject = $this->renderer->render((string) $template->subject, $client, true, $fields, $period);
            $body = $this->renderer->render((string) $template->body, $client, true, $fields, $period);
        } catch (RuntimeException $e) {
            throw AgentRefusal::invalid("The template {$template->key} has a placeholder with no value for {$client->name} in {$period} ({$e->getMessage()}). Fill it in or change the template.");
        }

        $email ??= new ClientSummaryEmail([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'period' => $period,
            'recipients' => $this->rememberedRecipients($client, $period),
        ]);
        $email->fill([
            'template_id' => $template->id,
            'subject' => self::oneLine($subject),
            'body' => mb_rtrim($body)."\n",
            'status' => ClientSummaryEmail::DRAFT,
        ]);
        $email->error = null;
        $email->text_edited_at = null;
        $email->drafted_at = now();
        $email->drafted_via = app(AgentCallContext::class)->current() === null ? MonthCloseStep::VIA_WEB : MonthCloseStep::VIA_AGENT;
        $email->save();

        return $email;
    }

    /** The one named, else the one this email or the client's last one used, else the default, else the first. */
    private function template(Client $client, string $period, ?string $key, ?ClientSummaryEmail $email): MessageTemplate
    {
        $templates = MessageTemplate::query()->where('account_id', $client->account_id)->where('channel', MessageTemplate::EMAIL)->orderBy('id')->get();
        if ($templates->isEmpty()) {
            throw AgentRefusal::invalid('Add an email template first.');
        }
        if ($key !== null && $key !== '') {
            return $templates->firstWhere('key', $key)
                ?? throw AgentRefusal::invalid("No email template {$key}.", ['valid_templates' => $templates->pluck('key')->all()]);
        }

        $previous = $email?->template_id ?? ClientSummaryEmail::query()->where('client_id', $client->id)->where('period', '<', $period)->orderByDesc('period')->value('template_id');

        return $templates->firstWhere('id', $previous) ?? $templates->firstWhere('is_default', true) ?? $templates->first();
    }

    /** @return list<string> */
    private function rememberedRecipients(Client $client, string $period): array
    {
        $last = ClientSummaryEmail::query()->where('client_id', $client->id)->where('period', '<', $period)->orderByDesc('period')->first();
        $valid = array_column($this->candidates($client), 'email');

        return array_values(array_intersect(array_map(mb_strtolower(...), (array) $last?->recipients), $valid));
    }

    /**
     * @param  array<int, mixed>  $recipients
     * @return list<string>
     */
    private function checkedRecipients(Client $client, array $recipients): array
    {
        $valid = array_column($this->candidates($client), 'email');
        $picked = [];
        foreach ($recipients as $recipient) {
            $email = mb_strtolower(mb_trim((string) $recipient));
            if (! in_array($email, $valid, true)) {
                throw AgentRefusal::invalid("{$email} is not an address of {$client->name} or its contacts.", ['field' => 'recipients', 'valid_recipients' => $valid]);
            }
            $picked[$email] = $email;
        }

        return array_values($picked);
    }

    private function assertEditable(ClientSummaryEmail $email): void
    {
        if ($email->status === ClientSummaryEmail::SENT) {
            throw new AgentRefusal(AgentRefusal::NOT_A_DRAFT, "The summary email for {$email->period} was sent and is locked.");
        }
        if (! $email->isEditable()) {
            throw new AgentRefusal(AgentRefusal::NOT_A_DRAFT, "The summary email for {$email->period} is {$email->status}; it cannot change until that is settled.");
        }
    }

    private function assertSendable(ClientSummaryEmail $email, bool $resendUnconfirmed): void
    {
        $ok = $email->isEditable()
            || $email->neverHandedOff()
            || ($resendUnconfirmed && ($email->status === ClientSummaryEmail::UNCONFIRMED || $email->isLost()));
        if ($ok) {
            return;
        }

        throw new AgentRefusal(AgentRefusal::REFUSED, match (true) {
            $email->status === ClientSummaryEmail::SENT => "The summary email for {$email->period} was already sent.",
            $email->status === ClientSummaryEmail::QUEUED && ! $email->isLost() => 'This email is being sent right now.',
            default => 'It is unknown whether this email reached the client. Check the mailbox, then send again only if it is not there.',
        });
    }

    private function lockedRow(ClientSummaryEmail $email): ClientSummaryEmail
    {
        return ClientSummaryEmail::query()->lockForUpdate()->findOrFail($email->id);
    }

    /** @param  array<string, mixed>  $values */
    private function move(int $id, int $attempt, array $values): bool
    {
        return ClientSummaryEmail::query()->whereKey($id)->where('attempt', $attempt)->where('status', ClientSummaryEmail::QUEUED)
            ->update([...$values, 'updated_at' => now()]) === 1;
    }

    private function assertBilling(Client $client): void
    {
        if (! $client->account->hasFeature('billing')) {
            throw AgentRefusal::featureOff('billing');
        }
    }

    private function period(string $period): string
    {
        if (! LocalCalendar::isMonth($period)) {
            throw AgentRefusal::invalid('The period must be a month as YYYY-MM.');
        }

        return $period;
    }
}
