<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientReportRevision extends Model
{
    public const string REASON_UPDATE = 'update';

    public const string REASON_REGENERATE = 'regenerate';

    public const string REASON_FINALIZE = 'finalize';

    public const string REASON_REOPEN = 'reopen';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'client_report_id',
        'user_id',
        'reason',
        'body_markdown_before',
        'status_before',
        'contracted_hours_before',
        'actual_hours_before',
        'currency_before',
        'composer_key_before',
        'created_at',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'contracted_hours_before' => 'decimal:2',
            'actual_hours_before' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
