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
 * @property int|null $parent_id
 * @property int|null $retried_from_run_id
 * @property string $importer
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read IngestRun|null $parent
 *
 * @method static IngestRunFactory factory(...$parameters)
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

    public function user(): BelongsTo
    {
        $userModelClass = (string) config('auth.providers.users.model', User::class);

        return $this->belongsTo($userModelClass, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IngestRow::class);
    }

    public function batch(): ?Batch
    {
        return $this->batch_id ? Bus::findBatch($this->batch_id) : null;
    }

    public function finalize(): void
    {
        $this->refresh();

        $finalStatus = IngestStatus::COMPLETED;
        if ($this->failed_rows > 0) {
            $finalStatus = IngestStatus::COMPLETED_WITH_ERRORS;
        }

        $this->update([
            'status' => $finalStatus,
            'completed_at' => now(),
            'summary' => $this->summary ?? [
                'errors' => [],
                'warnings' => [],
                'meta' => [
                    'successful_rows' => $this->successful_rows,
                    'failed_rows' => $this->failed_rows,
                    'total_rows' => $this->total_rows,
                ],
            ],
        ]);
    }

    protected static function newFactory(): IngestRunFactory
    {
        return IngestRunFactory::new();
    }
}
