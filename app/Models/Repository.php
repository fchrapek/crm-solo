<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Repository extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'local_path',
        'remote_url',
        'provider',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function localPathExists(): bool
    {
        return $this->local_path !== null && is_dir($this->local_path);
    }
}
