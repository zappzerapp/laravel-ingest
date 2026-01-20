<?php

declare(strict_types=1);

namespace LaravelIngest\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LaravelIngest\Database\Factories\IngestRowFactory;

/**
 * @property int $id
 * @property int $ingest_run_id
 * @property int $row_number
 * @property string $status
 * @property array $data
 * @property array|null $errors
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read IngestRun $ingestRun
 *
 * @method static IngestRowFactory factory(...$parameters)
 */
class IngestRow extends Model
{
    /** @use HasFactory<IngestRowFactory> */
    use HasFactory;
    use Prunable;

    protected $table = 'ingest_rows';
    protected $guarded = [];
    protected $casts = [
        'data' => 'array',
        'errors' => 'array',
    ];

    /**
     * @return Builder<IngestRow>
     */
    public function prunable(): Builder
    {
        $days = config('ingest.prune_days', 30);

        return static::where('created_at', '<=', now()->subDays($days));
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
