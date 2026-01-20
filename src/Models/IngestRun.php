<?php

declare(strict_types=1);

namespace LaravelIngest\Models;

use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use LaravelIngest\Database\Factories\IngestRunFactory;
use LaravelIngest\Enums\IngestStatus;

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
        ]);
    }

    protected static function newFactory(): IngestRunFactory
    {
        return IngestRunFactory::new();
    }
}
