<?php

namespace LaravelIngest;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
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
    public bool $useTransaction = false;

    /**
     * @throws InvalidConfigurationException
     */
    private function __construct(string $modelClass)
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidConfigurationException(sprintf(
                "The class '%s' must be an instance of Illuminate\Database\Eloquent\Model. " .
                "Please check the class name passed to IngestConfig::for().",
                $modelClass
            ));
        }
        $this->model = $modelClass;
        $this->chunkSize = config('ingest.chunk_size', 100);
        $this->disk = config('ingest.disk', 'local');
    }

    /**
     * @throws InvalidConfigurationException
     */
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

    public function map(string $sourceField, string $modelAttribute): self
    {
        $this->mappings[$sourceField] = ['attribute' => $modelAttribute, 'transformer' => null];
        return $this;
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function mapAndTransform(string $sourceField, string $modelAttribute, Closure $transformer): self
    {
        $this->mappings[$sourceField] = ['attribute' => $modelAttribute, 'transformer' => new SerializableClosure($transformer)];
        return $this;
    }

    /**
     * @param string $sourceField Das Feld in der Quelldatei (z.B. 'category_name')
     * @param string $relationName Der Name der Eloquent-Relation im Hauptmodell (z.B. 'category')
     * @param string $relatedModel Die Klasse des verwandten Models (z.B. Category::class)
     * @param string $relatedKey Die Spalte im verwandten Model, nach der gesucht wird (z.B. 'name')
     * @return $this
     * @throws InvalidConfigurationException
     */
    public function relate(string $sourceField, string $relationName, string $relatedModel, string $relatedKey = 'id'): self
    {
        if (!is_subclass_of($relatedModel, Model::class)) {
            throw new InvalidConfigurationException("Class {$relatedModel} must be an Eloquent Model.");
        }

        $this->relations[$sourceField] = [
            'relation' => $relationName,
            'model' => $relatedModel,
            'key' => $relatedKey,
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
        $this->useTransaction = true;
        return $this;
    }
}