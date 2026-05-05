<?php

declare(strict_types=1);

namespace LaravelIngest;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\ConditionalMappingInterface;
use LaravelIngest\Contracts\HasMappings;
use LaravelIngest\Contracts\ImportEventHandlerInterface;
use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\Contracts\SourceInterface;
use LaravelIngest\Contracts\TransformerInterface;
use LaravelIngest\Contracts\ValidatorInterface;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Services\DataTransformationService;

class IngestConfig implements HasMappings
{
    public string $model;
    public SourceType|SourceInterface $sourceType;
    public array $sourceOptions = [];
    public string|array|null $keyedBy = null;
    public DuplicateStrategy $duplicateStrategy = DuplicateStrategy::SKIP;
    public ?array $timestampComparison = null;
    public array $mappings = [];
    public array $relations = [];
    public array $manyRelations = [];
    public array $validationRules = [];
    public bool $useModelRules = false;
    public bool $strictHeaders = false;
    public int $chunkSize;
    public ?string $disk = null;
    public TransactionMode $transactionMode = TransactionMode::NONE;
    public ?SerializableClosure $beforeRowCallback = null;
    public ?SerializableClosure $afterRowCallback = null;
    public ?SerializableClosure $modelResolver = null;
    public array $conditionalMappings = [];
    public array $validators = [];
    public ?ImportEventHandlerInterface $eventHandler = null;
    public ?array $expectedSchema = null;
    public array $nestedConfigs = [];
    public bool $tracingEnabled = false;
    public bool $traceTransformations = false;
    public bool $traceMappings = false;

    /**
     * @throws InvalidConfigurationException
     */
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

    /**
     * @throws InvalidConfigurationException
     */
    public static function for(string $modelClass): self
    {
        return new self($modelClass);
    }

    public function fromSource(SourceType|SourceInterface $sourceType, array $options = []): self
    {
        $this->sourceType = $sourceType;
        $this->sourceOptions = $options;

        return $this;
    }

    public function keyedBy(string $sourceField): static
    {
        $this->keyedBy = $sourceField;

        return $this;
    }

    public function onDuplicate(DuplicateStrategy $strategy): self
    {
        $this->duplicateStrategy = $strategy;

        return $this;
    }

    public function map(string|array $sourceField, string $modelAttribute): static
    {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'transformer' => null,
            'transformers' => [],
            'aliases' => $aliases,
        ];

        return $this;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
     */
    public function mapAndTransform(
        string|array $sourceField,
        string $modelAttribute,
        Closure|TransformerInterface|string|array $transformer
    ): static {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $transformers = is_array($transformer) ? $transformer : [$transformer];

        $normalizedTransformers = array_map(
            fn($t) => $this->normalizeTransformer($t),
            $transformers
        );

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'transformer' => count($normalizedTransformers) === 1 ? $normalizedTransformers[0] : null,
            'transformers' => $normalizedTransformers,
            'aliases' => $aliases,
        ];

        return $this;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
     */
    public function mapAndValidate(
        string|array $sourceField,
        string $modelAttribute,
        ValidatorInterface|string|array $validator
    ): static {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $validators = is_array($validator) ? $validator : [$validator];

        $this->validators[$primaryField] = [
            'attribute' => $modelAttribute,
            'validators' => array_map(
                fn($v) => $this->normalizeValidator($v),
                $validators
            ),
            'aliases' => $aliases,
        ];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'transformer' => null,
            'transformers' => [],
            'aliases' => $aliases,
        ];

        return $this;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
     */
    public function mapTransformAndValidate(
        string|array $sourceField,
        string $modelAttribute,
        array $transformers,
        array $validators
    ): self {
        $this->mapAndTransform($sourceField, $modelAttribute, $transformers);

        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $this->validators[$primaryField] = [
            'attribute' => $modelAttribute,
            'validators' => array_map(
                fn($v) => $this->normalizeValidator($v),
                $validators
            ),
            'aliases' => is_array($sourceField) ? array_slice($sourceField, 1) : [],
        ];

        return $this;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
     */
    public function mapWhen(
        string|array $sourceField,
        string $modelAttribute,
        Closure|ConditionalMappingInterface $condition,
        Closure|TransformerInterface|string|null $transformer = null,
        Closure|ValidatorInterface|string|null $validator = null
    ): self {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->conditionalMappings[] = [
            'sourceField' => $primaryField,
            'attribute' => $modelAttribute,
            'condition' => $condition,
            'transformer' => $transformer ? $this->normalizeTransformer($transformer) : null,
            'validator' => $validator ? $this->normalizeValidator($validator) : null,
            'aliases' => $aliases,
        ];

        return $this;
    }

    public function applyMapping(MappingInterface $mapping, string $prefix = ''): self
    {
        return $mapping->apply($this, $prefix);
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function nest(string $sourceField, Closure $callback): self
    {
        $nestedConfig = new NestedIngestConfig();
        $callback($nestedConfig);

        $this->nestedConfigs[$sourceField] = $nestedConfig;

        return $this;
    }

    /**
     * @throws InvalidConfigurationException
     */
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

    /**
     * @param  array<string, array{type: string, required?: bool, nullable?: bool}>  $schema
     */
    public function expectSchema(array $schema): self
    {
        $this->expectedSchema = $schema;

        return $this;
    }

    public function strictHeaders(bool $strict = true): self
    {
        $this->strictHeaders = $strict;

        return $this;
    }

    public function compareTimestamp(string $sourceColumn, string $dbColumn = 'updated_at'): self
    {
        $this->timestampComparison = [
            'source_column' => $sourceColumn,
            'db_column' => $dbColumn,
        ];

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

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function beforeRow(Closure $callback): self
    {
        $this->beforeRowCallback = new SerializableClosure($callback);

        return $this;
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function afterRow(Closure $callback): self
    {
        $this->afterRowCallback = new SerializableClosure($callback);

        return $this;
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function resolveModelUsing(Closure $callback): self
    {
        $this->modelResolver = new SerializableClosure($callback);

        return $this;
    }

    public function withEventHandler(ImportEventHandlerInterface $handler): self
    {
        $this->eventHandler = $handler;

        return $this;
    }

    public function withTracing(): self
    {
        $this->tracingEnabled = true;
        $this->traceTransformations = true;
        $this->traceMappings = true;

        return $this;
    }

    public function traceTransformations(): self
    {
        $this->tracingEnabled = true;
        $this->traceTransformations = true;

        return $this;
    }

    public function traceMappings(): self
    {
        $this->tracingEnabled = true;
        $this->traceMappings = true;

        return $this;
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function relateMany(
        string $sourceField,
        string $relationName,
        string $relatedModel,
        string $relatedKey = 'id',
        string $separator = ','
    ): self {
        if (!is_subclass_of($relatedModel, Model::class)) {
            throw new InvalidConfigurationException("Class {$relatedModel} must be an Eloquent Model.");
        }

        $this->manyRelations[$sourceField] = [
            'relation' => $relationName,
            'model' => $relatedModel,
            'key' => $relatedKey,
            'separator' => $separator,
        ];

        return $this;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
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

    /**
     * @return array<string, array<string>>
     */
    public function validateRow(array $rowData, ?DataTransformationService $service = null): array
    {
        $service ??= new DataTransformationService();

        return $service->processValidators($rowData, $this->validators, $this);
    }

    public function shouldApplyConditional(array $conditional, array $rowData): bool
    {
        $condition = $conditional['condition'];

        if ($condition instanceof ConditionalMappingInterface) {
            return $condition->shouldApply($rowData);
        }

        if ($condition instanceof Closure) {
            return $condition($rowData);
        }

        return true;
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

    public function getAttributeForKeyedBy(): ?string
    {
        if ($this->keyedBy === null) {
            return null;
        }

        $firstKey = is_array($this->keyedBy) ? ($this->keyedBy[0] ?? null) : $this->keyedBy;
        if ($firstKey === null) {
            return null;
        }

        foreach ($this->mappings as $sourceField => $map) {
            $allSourceFields = array_merge([$sourceField], $map['aliases']);
            if (in_array($firstKey, $allSourceFields, true)) {
                return $map['attribute'];
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function getAttributesForKeyedBy(): array
    {
        if ($this->keyedBy === null) {
            return [];
        }

        $keyedBy = is_array($this->keyedBy) ? $this->keyedBy : [$this->keyedBy];
        $attributes = [];

        foreach ($keyedBy as $key) {
            foreach ($this->mappings as $sourceField => $map) {
                $allSourceFields = array_merge([$sourceField], $map['aliases']);
                if (in_array($key, $allSourceFields, true)) {
                    $attributes[] = $map['attribute'];
                    break;
                }
            }
        }

        return $attributes;
    }

    /**
     * @throws InvalidConfigurationException|PhpVersionNotSupportedException
     */
    private function normalizeTransformer(Closure|TransformerInterface|string $transformer): SerializableClosure|TransformerInterface
    {
        if ($transformer instanceof Closure) {
            return new SerializableClosure($transformer);
        }

        if ($transformer instanceof TransformerInterface) {
            return $transformer;
        }

        if (!class_exists($transformer)) {
            throw new InvalidConfigurationException(
                "Transformer class '{$transformer}' does not exist."
            );
        }

        if (!is_subclass_of($transformer, TransformerInterface::class)) {
            throw new InvalidConfigurationException(
                "Transformer class '{$transformer}' must implement " . TransformerInterface::class
            );
        }

        return new $transformer();
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function normalizeValidator(ValidatorInterface|string $validator): ValidatorInterface
    {
        if ($validator instanceof ValidatorInterface) {
            return $validator;
        }

        if (!class_exists($validator)) {
            throw new InvalidConfigurationException(
                "Validator class '{$validator}' does not exist."
            );
        }

        if (!is_subclass_of($validator, ValidatorInterface::class)) {
            throw new InvalidConfigurationException(
                "Validator class '{$validator}' must implement " . ValidatorInterface::class
            );
        }

        return new $validator();
    }
}
