<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use App\Models\OutboundMessage;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Builder;

/** Figma: SMS / Kolejka (607:5435): from today on soonest first, then everything earlier newest first, paged. */
final class MessageQueue
{
    public const int PER_PAGE = 25;

    /** Status filter => the stored statuses it shows. */
    public const array STATUSES = [
        'pending' => [OutboundMessage::PENDING, OutboundMessage::SENDING],
        'sent' => [OutboundMessage::SENT, OutboundMessage::SIMULATED],
        'failed' => [OutboundMessage::FAILED],
        'not_sent' => [OutboundMessage::CANCELLED, OutboundMessage::SKIPPED, OutboundMessage::SUPERSEDED],
    ];

    public function __construct(private readonly MessageRows $rows) {}

    /**
     * @param  array<string, mixed>  $input  status, search, client from the query string
     * @return array<string, mixed>
     */
    public function props(Account $account, array $input): array
    {
        $status = is_string($input['status'] ?? null) && isset(self::STATUSES[$input['status']]) ? $input['status'] : null;
        $search = is_string($input['search'] ?? null) ? mb_trim($input['search']) : '';
        $client = is_numeric($input['client'] ?? null) ? (int) $input['client'] : null;
        $today = LocalCalendar::today();
        $start = $today->copy()->startOfDay()->utc();

        // A message not yet sent has no frozen text, so its template text is searched too.
        $page = OutboundMessage::query()
            ->where('account_id', $account->id)
            ->when($status !== null, fn (Builder $q) => $q->whereIn('status', self::STATUSES[$status]))
            ->when($client !== null, fn (Builder $q) => $q->where('client_id', $client))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'))
                ->orWhere('body', 'like', '%'.$search.'%')
                ->orWhere(fn (Builder $pending) => $pending->whereNull('body')->whereHas('rule.template', fn (Builder $t) => $t->where('body', 'like', '%'.$search.'%')))))
            ->with(['rule.template', 'client.account', 'client.assignee'])
            // From today on soonest first, then everything earlier newest first: one order, so paging never splits the two.
            ->orderByRaw('CASE WHEN scheduled_for >= ? THEN 0 ELSE 1 END', [$start])
            ->orderByRaw('CASE WHEN scheduled_for >= ? THEN scheduled_for END ASC', [$start])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['status' => $status, 'search' => $search, 'client' => $client],
            'today' => $today->toDateString(),
            'tomorrow' => $today->copy()->addDay()->toDateString(),
            'messages' => $page->through(fn (OutboundMessage $message): array => $this->rows->row($message)),
            'sending' => app(MessageSettings::class)->sendingOf($account),
        ];
    }
}
