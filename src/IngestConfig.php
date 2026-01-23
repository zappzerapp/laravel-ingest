<?php

declare(strict_types=1);

namespace LaravelIngest;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Exceptions\InvalidConfigurationException;

class IngestConfig
{
    public string $model;
    public SourceType $sourceType;
    public array $sourceOptions = [];
    public ?string $keyedBy = null;
    public DuplicateStrategy $duplicateStrategy = DuplicateStrategy::SKIP;
    public array $mappings = [];
    public array $relations = [];
    public array $validationRules = [];
    public bool $useModelRules = false;
    public int $chunkSize;
    public ?string $disk = null;
    public TransactionMode $transactionMode = TransactionMode::NONE;
    public ?SerializableClosure $beforeRowCallback = null;
    public ?SerializableClosure $afterRowCallback = null;
    public ?SerializableClosure $modelResolver = null;

    private function __construct(string $modelClass)
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidConfigurationException(sprintf(
                "The class '%s' must be an instance of Illuminate\Database\Eloquent\Model.",
                $modelClass
            ));
        }
        $this->model = $modelClass;
        $this->chunkSize = config('ingest.chunk_size', 100);
        $this->disk = config('ingest.disk', 'local');
    }

    public static function for(string $modelClass): self
    {
        return new self($modelClass);
    }

    public function fromSource(SourceType $sourceType, array $options = []): self
    {
        $this->sourceType = $sourceType;
        $this->sourceOptions = $options;

        return $this;
    }

    public function keyedBy(string $sourceField): self
    {
        $this->keyedBy = $sourceField;

        return $this;
    }

    public function onDuplicate(DuplicateStrategy $strategy): self
    {
        $this->duplicateStrategy = $strategy;

        return $this;
    }

    public function map(string|array $sourceField, string $modelAttribute): self
    {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'transformer' => null,
            'aliases' => $aliases,
        ];

        return $this;
    }

    public function mapAndTransform(string|array $sourceField, string $modelAttribute, Closure $transformer): self
    {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'transformer' => new SerializableClosure($transformer),
            'aliases' => $aliases,
        ];

        return $this;
    }

    public function relate(
        string $sourceField,
        string $relationName,
        string $relatedModel,
        string $relatedKey = 'id',
        bool $createIfMissing = false
    ): self {
        if (!is_subclass_of($relatedModel, Model::class)) {
            throw new InvalidConfigurationException("Class {$relatedModel} must be an Eloquent Model.");
        }

        $this->relations[$sourceField] = [
            'relation' => $relationName,
            'model' => $relatedModel,
            'key' => $relatedKey,
            'createIfMissing' => $createIfMissing,
        ];

        return $this;
    }

    public function validate(array $rules): self
    {
        $this->validationRules = array_merge($this->validationRules, $rules);

        return $this;
    }

    public function validateWithModelRules(): self
    {
        $this->useModelRules = true;

        return $this;
    }

    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;

        return $this;
    }

    public function setDisk(string $diskName): self
    {
        $this->disk = $diskName;

        return $this;
    }

    public function atomic(): self
    {
        $this->transactionMode = TransactionMode::CHUNK;

        return $this;
    }

    public function transactionMode(TransactionMode $mode): self
    {
        $this->transactionMode = $mode;

        return $this;
    }

    /** @throws PhpVersionNotSupportedException */
    public function beforeRow(Closure $callback): self
    {
        $this->beforeRowCallback = new SerializableClosure($callback);

        return $this;
    }

    /** @throws PhpVersionNotSupportedException */
    public function afterRow(Closure $callback): self
    {
        $this->afterRowCallback = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Set a callback to dynamically resolve the model class based on row data.
     *
     * The callback receives the row data array and should return a model class string.
     *
     * @throws PhpVersionNotSupportedException
     */
    public function resolveModelUsing(Closure $callback): self
    {
        $this->modelResolver = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Resolve the model class for a given row.
     *
     * If a model resolver is set, it will be called with the row data.
     * Otherwise, the default model class will be returned.
     */
    public function resolveModelClass(array $rowData): string
    {
        if ($this->modelResolver) {
            $resolvedClass = call_user_func($this->modelResolver->getClosure(), $rowData);

            if (!is_subclass_of($resolvedClass, Model::class)) {
                throw new InvalidConfigurationException(sprintf(
                    "The resolved class '%s' must be an instance of Illuminate\Database\Eloquent\Model.",
                    $resolvedClass
                ));
            }

            return $resolvedClass;
        }

        return $this->model;
    }

    public function getHeaderNormalizationMap(): array
    {
        $map = [];
        foreach ($this->mappings as $primaryField => $config) {
            $map[$primaryField] = $primaryField;
            foreach ($config['aliases'] as $alias) {
                $map[$alias] = $primaryField;
            }
        }

        return $map;
    }
}
