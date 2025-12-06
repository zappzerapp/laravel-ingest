<?php

declare(strict_types=1);

namespace LaravelIngest\Models;

use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use LaravelIngest\Database\Factories\IngestRunFactory;
use LaravelIngest\Enums\IngestStatus;

/**
 * @property int $id
 * @property string $importer_slug
 * @property int|null $user_id
 * @property IngestStatus $status
 * @property string|null $batch_id
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
 * @property int|null $retried_from_run_id
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IngestRow> $rows
 * @property-read IngestRun|null $originalRun
 *
 * @method static IngestRunFactory factory(...$parameters)
 */
class IngestRun extends Model
{
    /** @use HasFactory<IngestRunFactory> */
    use HasFactory;

    protected $table = 'ingest_runs';
    protected $guarded = [];
    protected $casts = [
        'status' => IngestStatus::class,
        'summary' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModelClass */
        $userModelClass = $this->getUserModelClass();

        return $this->belongsTo($userModelClass, 'user_id');
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

    public function originalRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retried_from_run_id');
    }

    public function batch(): ?Batch
    {
        return $this->batch_id ? Bus::findBatch($this->batch_id) : null;
    }

    protected static function newFactory(): IngestRunFactory
    {
        return IngestRunFactory::new();
    }

    private function getUserModelClass(): string
    {
        return (string) config('auth.providers.users.model', User::class);
    }
}
