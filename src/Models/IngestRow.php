<?php

declare(strict_types=1);

namespace LaravelIngest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelIngest\Database\Factories\IngestRowFactory;

class IngestRow extends Model
{
    use HasFactory;
    use Prunable;

    protected $table = 'ingest_rows';
    protected $guarded = [];
    protected $casts = [
        'data' => 'array',
        'errors' => 'array',
    ];

    public function prunable()
    {
        return static::where('created_at', '<=', now()->subMonth());
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(IngestRun::class);
    }

    protected static function newFactory(): IngestRowFactory
    {
        return IngestRowFactory::new();
    }
}
