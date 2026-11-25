<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/** Plain-text lines for a website form as IntakeForms describes it, secrets only as set or not. */
trait PrintsIntakeForms
{
    use AgentConsoleOutput;

    /** @param  array<string, mixed>  $form */
    private function formLine(array $form): string
    {
        $notices = $form['notices'];

        return "#{$form['id']} ".$this->literal($form['name']).' ('.($form['active'] ? 'active' : 'inactive').')'
            .' '.$this->literal($form['endpoint'])
            .' · '.$this->literal($form['pipeline']).' / '.$this->literal($form['source'])
            .' · origins '.$this->literal(implode(', ', $form['origins']))
            .' · notices to '.$this->literal($form['notify_to'] ?? 'nobody')
            .' · Turnstile secret '.($form['turnstile_secret_set'] ? 'set' : 'not set')
            .', webhook secret '.($form['webhook_secret_set'] ? 'set' : 'not set')
            ." · notices waiting {$notices['waiting']}, failed {$notices['failed']}, sent {$notices['sent']}, skipped {$notices['skipped']}";
    }
}
