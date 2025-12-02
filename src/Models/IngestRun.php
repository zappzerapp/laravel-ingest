<?php

namespace LaravelIngest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LaravelIngest\Database\Factories\IngestRunFactory;
use LaravelIngest\Enums\IngestStatus;

/**
 * @property int $id
 * @property string $importer_slug
 * @property int|null $user_id
 * @property IngestStatus $status
 * @property string|null $original_filename
 * @property string|null $processed_filepath
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property int $failed_rows
 * @property array|null $summary
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IngestRun extends Model
{
    use HasFactory;

    protected $table = 'ingest_runs';

    protected $guarded = [];

    protected $casts = [
        'status' => IngestStatus::class,
        'summary' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): IngestRunFactory
    {
        return IngestRunFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->getUserModelClass(), 'user_id');
    }

    private function getUserModelClass(): string
    {
        return config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);
    }

    public function finalize(): void
    {
        $stats = $this->rows()
            ->selectRaw('count(*) as total, sum(case when status = "success" then 1 else 0 end) as success, sum(case when status = "failed" then 1 else 0 end) as failed')
            ->first();

        $this->update([
            'processed_rows' => $stats->total ?? 0,
            'successful_rows' => $stats->success ?? 0,
            'failed_rows' => $stats->failed ?? 0,
            'status' => ($stats->failed ?? 0) > 0 ? IngestStatus::FAILED : IngestStatus::COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IngestRow::class);
    }
}