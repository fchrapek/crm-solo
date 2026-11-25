<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\IntakeForm;
use App\Services\Agent\AgentRefusal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * The one way to set, replace or clear a form's secrets, for the server commands and the owner's settings form alike;
 * never an agent verb or tool, and nothing here returns a stored secret.
 */
final class IntakeFormSecrets
{
    public const int TURNSTILE_MAX = 255;

    /** @throws AgentRefusal */
    public function setTurnstile(int $accountId, int $formId, #[SensitiveParameter] string $secret): IntakeForm
    {
        $form = $this->form($accountId, $formId);
        if ($secret === '' || mb_strlen($secret) > self::TURNSTILE_MAX || preg_match('/\p{Cc}/u', $secret) === 1 || ! mb_check_encoding($secret, 'UTF-8')) {
            throw AgentRefusal::invalid(__('The Turnstile secret must be one line of at most 255 characters.'), ['fields' => ['turnstile_secret']]);
        }

        $this->store($form, ['turnstile_secret' => $secret]);

        return $form;
    }

    /** @throws AgentRefusal */
    public function clearTurnstile(int $accountId, int $formId): IntakeForm
    {
        $form = $this->form($accountId, $formId);
        $this->store($form, ['turnstile_secret' => null]);

        return $form;
    }

    /**
     * A new webhook secret, stored as its hash; the plain value is returned this once and never again.
     *
     * @throws AgentRefusal
     */
    public function rotateWebhook(int $accountId, int $formId): string
    {
        $form = $this->form($accountId, $formId);
        $secret = 'crmhook_'.Str::random(40);
        $this->store($form, ['webhook_secret_hash' => hash('sha256', $secret)]);

        return $secret;
    }

    /** @throws AgentRefusal */
    public function clearWebhook(int $accountId, int $formId): IntakeForm
    {
        $form = $this->form($accountId, $formId);
        $this->store($form, ['webhook_secret_hash' => null]);

        return $form;
    }

    /**
     * A failed write never carries the secret on: the database error holds it in its SQL, so only its class and code
     * are logged and a plain error goes up.
     *
     * @param  array<string, ?string>  $secrets
     */
    private function store(IntakeForm $form, #[SensitiveParameter] array $secrets): void
    {
        try {
            $form->forceFill($secrets)->save();
        } catch (Throwable $e) {
            Log::warning('Intake form secret was not stored.', ['form' => $form->id, 'exception' => $e::class, 'code' => $e->getCode()]);

            throw new RuntimeException(__('The secret could not be stored.'));
        }
    }

    /** @throws AgentRefusal */
    private function form(int $accountId, int $formId): IntakeForm
    {
        if (config('app.demo')) {
            throw new AgentRefusal(AgentRefusal::REFUSED, __('The demo has no intake forms.'));
        }

        return IntakeForm::query()->where('account_id', $accountId)->whereKey($formId)->first()
            ?? throw AgentRefusal::invalid(__('No form #:form in this account.', ['form' => $formId]), ['form_id' => $formId]);
    }
}
