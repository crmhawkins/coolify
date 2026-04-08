<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerCleanupExecution extends BaseModel
{
    protected $guarded = [];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
// resync-marker 2026-04-08
