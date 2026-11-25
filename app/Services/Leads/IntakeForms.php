<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\IntakeForm;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Services\Agent\AgentRefusal;
use Symfony\Component\Mime\Address;
use Throwable;

/** The one way to list, create and change website forms' non-secret settings, for the server commands and the agent verbs alike. */
final class IntakeForms
{
    public static function isOrigin(string $origin): bool
    {
        return self::canonicalOrigin($origin) !== null;
    }

    /**
     * The origin as a browser sends it, or null: a lowercase scheme, a valid host name (an international one in its ASCII
     * form) or IP literal, an optional port from 1 to 65535 without the scheme's default; plain http only for a local preview.
     */
    public static function canonicalOrigin(string $origin): ?string
    {
        if ($origin !== mb_strtolower($origin) || preg_match('~\A(https?)://(\[[0-9a-f:.]+\]|[^\s/:?#@\[\]\\\\]+)(?::([0-9]{1,5}))?\z~u', $origin, $m) !== 1) {
            return null;
        }
        [, $scheme, $host] = $m;
        $port = isset($m[3]) ? (int) $m[3] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            $packed = filter_var(mb_substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton(mb_substr($host, 1, -1));
            // An IPv4 address inside IPv6 (mapped or compatible) is no site origin, and browsers write it another way.
            if ($packed === false || (str_starts_with($packed, str_repeat("\0", 10)) && in_array(mb_substr($packed, 10, 2, '8bit'), ["\xff\xff", "\0\0"], true) && ! in_array(inet_ntop($packed), ['::', '::1'], true))) {
                return null;
            }
            // The short form a browser writes, so [2001:0db8::0:1] matches the Origin it sends.
            $host = '['.inet_ntop($packed).']';
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $host = (string) inet_ntop((string) inet_pton($host));
        } else {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii) || ! self::isHostName(mb_strtolower($ascii))) {
                return null;
            }
            $host = mb_strtolower($ascii);
        }

        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return null;
        }
        $default = $scheme === 'https' ? 443 : 80;

        return "{$scheme}://{$host}".($port !== null && $port !== $default ? ":{$port}" : '');
    }

    /**
     * What a command or a verb passes on: a flag left out arrives as null or an empty list, which means no change there,
     * never a cleared field (a cleared field only comes as an explicit key).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function onlyGiven(array $input): array
    {
        return array_filter($input, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array{forms: list<array<string, mixed>>, total: int} */
    public function list(int $accountId): array
    {
        self::refuseInDemo();

        $counts = LeadIntake::query()
            ->where('account_id', $accountId)
            ->selectRaw('intake_form_id, notice_state, COUNT(*) AS total')
            ->groupBy('intake_form_id', 'notice_state')
            ->get()
            ->groupBy('intake_form_id');

        $forms = IntakeForm::query()->where('account_id', $accountId)->orderBy('id')->get()
            ->map(fn (IntakeForm $form): array => $this->describe($form, ($counts->get($form->id) ?? collect())->pluck('total', 'notice_state')->all()))
            ->values()
            ->all();

        return ['forms' => $forms, 'total' => count($forms)];
    }

    /**
     * @param  array{name?: mixed, pipeline?: mixed, source?: mixed, origins?: mixed, notify_to?: mixed, active?: mixed}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    public function create(int $accountId, array $input): array
    {
        self::refuseInDemo();
        $changes = $this->validated($accountId, $input, ['name', 'pipeline', 'source', 'origins'], editing: false);

        $form = IntakeForm::create([
            'account_id' => $accountId,
            'public_key' => IntakeForm::newPublicKey(),
            'is_active' => true,
            ...$changes,
        ]);

        return $this->describe($form->refresh(), []);
    }

    /**
     * @param  array{name?: mixed, pipeline?: mixed, source?: mixed, origins?: mixed, notify_to?: mixed, active?: mixed}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    public function edit(int $accountId, int $formId, array $input): array
    {
        self::refuseInDemo();
        $form = IntakeForm::query()->where('account_id', $accountId)->whereKey($formId)->first()
            ?? throw AgentRefusal::invalid(__('No form #:form in this account.', ['form' => $formId]), ['form_id' => $formId]);

        $changes = $this->validated($accountId, $input, [], editing: true);
        if ($changes === []) {
            throw AgentRefusal::invalid(__('Give at least one of --active, --name, --pipeline, --source, --origin or --notify-to.'));
        }
        $form->update($changes);

        $counts = LeadIntake::query()->where('intake_form_id', $form->id)->selectRaw('notice_state, COUNT(*) AS total')->groupBy('notice_state')->pluck('total', 'notice_state')->all();

        return $this->describe($form->refresh(), $counts);
    }

    /**
     * Why each form's failed notices failed: a reason code and its count, per form id.
     *
     * @return array<int, array<string, int>>
     */
    public function failureReasons(int $accountId): array
    {
        return LeadIntake::query()
            ->where('account_id', $accountId)
            ->where('notice_state', LeadIntake::FAILED)
            ->selectRaw('intake_form_id, failure_reason, COUNT(*) AS total')
            ->groupBy('intake_form_id', 'failure_reason')
            ->get()
            ->groupBy('intake_form_id')
            ->map(fn ($rows): array => $rows->mapWithKeys(fn (LeadIntake $row): array => [(string) ($row->failure_reason ?? 'unknown') => (int) $row->getAttribute('total')])->all())
            ->all();
    }

    /**
     * Never a secret: the Turnstile and webhook secrets show only as set or not.
     *
     * @param  array<string, int|string>  $counts
     * @return array<string, mixed>
     */
    public function describe(IntakeForm $form, array $counts): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'endpoint' => url("/intake/{$form->public_key}"),
            'origins' => array_values($form->allowed_origins ?? []),
            'pipeline' => $form->pipeline,
            'source' => $form->source,
            'notify_to' => $form->notify_to,
            'active' => (bool) $form->is_active,
            'turnstile_secret_set' => (string) $form->turnstile_secret !== '',
            'webhook_secret_set' => is_string($form->webhook_secret_hash),
            'notices' => [
                LeadIntake::WAITING => (int) ($counts[LeadIntake::WAITING] ?? 0),
                LeadIntake::FAILED => (int) ($counts[LeadIntake::FAILED] ?? 0),
                LeadIntake::SENT => (int) ($counts[LeadIntake::SENT] ?? 0),
                LeadIntake::SKIPPED => (int) ($counts[LeadIntake::SKIPPED] ?? 0),
            ],
        ];
    }

    /** A name a browser resolves: labels of 1 to 63 letters, digits and inner hyphens, 253 at most, not ending in a number. */
    private static function isHostName(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        $labels = explode('.', $host);
        // WHATWG URL: a host that ends in a number (decimal, or 0x with hex digits) is read as an IPv4 address, never a name.
        if (mb_strlen($host) > 253 || count($labels) < 2 || preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/', (string) end($labels)) === 1) {
            return false;
        }

        return array_filter($labels, fn (string $label): bool => preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) === [];
    }

    /** The same check the visitor's address gets: one the mailer accepts, or every notice would fail at send time. */
    private static function isMailbox(string $address): bool
    {
        try {
            new Address($address);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private static function refuseInDemo(): void
    {
        if (config('app.demo')) {
            throw new AgentRefusal(AgentRefusal::REFUSED, __('The demo has no intake forms.'));
        }
    }

    /**
     * The changes the input asks for, every key present checked, an empty value included; $required must be present.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $required
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    private function validated(int $accountId, array $input, array $required, bool $editing): array
    {
        $given = fn (string $key): bool => array_key_exists($key, $input);
        $changes = [];
        $problems = [];

        if ($given('name') || in_array('name', $required, true)) {
            $name = mb_trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120 || preg_match('/\p{Cc}/u', $name) === 1) {
                $problems['name'] = __('The name must be 1 to 120 characters on one line.');
            }
            $changes['name'] = $name;
        }
        if ($given('pipeline') || in_array('pipeline', $required, true)) {
            $pipeline = mb_trim((string) ($input['pipeline'] ?? ''));
            if (! in_array($pipeline, Lead::pipelines(), true)) {
                $problems['pipeline'] = __('Unknown pipeline; pipelines: :list.', ['list' => implode(', ', Lead::pipelines())]);
            }
            $changes['pipeline'] = $pipeline;
        }
        if ($given('source') || in_array('source', $required, true)) {
            $source = mb_trim((string) ($input['source'] ?? ''));
            if (! in_array($source, LeadVocabulary::sources($accountId), true)) {
                $problems['source'] = __('Unknown source for this account; sources: :list.', ['list' => implode(', ', LeadVocabulary::sources($accountId))]);
            }
            $changes['source'] = $source;
        }
        if ($given('origins') || in_array('origins', $required, true)) {
            $origins = array_map(fn (mixed $origin): ?string => self::canonicalOrigin(mb_trim((string) $origin)), (array) ($input['origins'] ?? []));
            if ($origins === []) {
                $problems['origins'] = __('A form needs at least one allowed site.');
            } elseif (in_array(null, $origins, true)) {
                $problems['origins'] = __('Every origin must be a bare origin such as https://www.example.test (http only for localhost).');
            }
            $changes['allowed_origins'] = array_values(array_unique(array_filter($origins)));
        }
        if ($given('notify_to')) {
            $notifyTo = mb_trim((string) $input['notify_to']);
            if ($notifyTo === '') {
                // A new form may start without a recipient; an existing one is never left without one.
                if ($editing) {
                    $problems['notify_to'] = __('A form needs a notify-to address; give another one instead of clearing it.');
                }
            } else {
                if (! self::isMailbox($notifyTo)) {
                    $problems['notify_to'] = __('The notify-to address is not an email address.');
                }
                $changes['notify_to'] = $notifyTo;
            }
        }
        if ($given('active')) {
            if (! is_bool($input['active'])) {
                $problems['active'] = __('Active takes yes or no.');
            }
            $changes['is_active'] = $input['active'] === true;
        }

        if ($problems !== []) {
            throw AgentRefusal::invalid(implode(' ', $problems), ['fields' => array_keys($problems), 'problems' => $problems]);
        }

        return $changes;
    }
}
