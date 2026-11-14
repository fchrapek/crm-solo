<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/** A client summary email exactly as frozen on its row: markdown sent as text, with an HTML rendering of it. */
final class ClientSummaryMail extends Mailable
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public readonly array $recipients,
        public readonly ?string $bccAddress,
        public readonly string $subjectLine,
        public readonly string $bodyMarkdown,
    ) {}

    /** Every line break stays a line break, as the app's preview shows it. */
    public static function renderHtml(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => "<br>\n"]]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: array_map(fn (string $email): Address => new Address($email), $this->recipients),
            bcc: $this->bccAddress === null ? [] : [new Address($this->bccAddress)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $html = self::renderHtml($this->bodyMarkdown);

        return new Content(
            text: 'mail.summary-text',
            with: ['plain' => $this->bodyMarkdown],
            htmlString: '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
        );
    }
}
