<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\IntakeForm;
use App\Models\Lead;
use App\Support\LocalCalendar;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A new lead from a website form, as plain text to the form's notify-to address, replying to the visitor. */
final class LeadIntakeNotice extends Mailable
{
    public function __construct(
        public readonly Lead $lead,
        public readonly IntakeForm $form,
        public readonly ?Lead $earlier,
        public readonly string $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->recipient)],
            replyTo: $this->lead->email === null ? [] : [new Address($this->lead->email, $this->lead->name)],
            subject: __('New lead: :name', ['name' => $this->lead->name]),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.summary-text', with: ['plain' => $this->body()]);
    }

    public function body(): string
    {
        $lines = [
            __('A new lead came in through the form :form.', ['form' => $this->form->name]),
            __('The lead in the CRM: :url', ['url' => route('leads.edit', $this->lead)]),
            '',
            __('Name: :value', ['value' => $this->lead->name]),
            __('Email: :value', ['value' => (string) $this->lead->email]),
        ];
        if ($this->lead->company !== null) {
            $lines[] = __('Company: :value', ['value' => $this->lead->company]);
        }
        $lines[] = '';
        $lines[] = (string) $this->lead->notes;
        $lines[] = '';
        if ($this->earlier !== null) {
            $lines[] = __('This address already wrote on :date: :url', [
                'date' => $this->earlier->captured_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString() ?? '',
                'url' => route('leads.edit', $this->earlier),
            ]);
        }

        return mb_rtrim(implode("\n", $lines));
    }
}
