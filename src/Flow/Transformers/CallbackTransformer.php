<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Transformers;

use DateTimeInterface;
use Flow\ETL\DataFrame;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry;
use Flow\ETL\Row\Entry\BooleanEntry;
use Flow\ETL\Row\Entry\DateTimeEntry;
use Flow\ETL\Row\Entry\FloatEntry;
use Flow\ETL\Row\Entry\IntegerEntry;
use Flow\ETL\Row\Entry\JsonEntry;
use Flow\ETL\Row\Entry\StringEntry;
use Flow\ETL\Transformation;
use Flow\Types\Value\Json;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class CallbackTransformer implements Transformation
{
    private SerializableClosure $callback;

    public function __construct(SerializableClosure $callback)
    {
        $this->callback = $callback;
    }

    public function transform(DataFrame $dataFrame): DataFrame
    {
        return $dataFrame->map(function (Row $row): Row {
            $rowData = $row->toArray();

            try {
                $closure = $this->callback->getClosure();
                $modifiedData = $closure($rowData);

                return Row::create(
                    ...$this->arrayToEntries($modifiedData)
                );
            } catch (Throwable $e) {
                $rowData['_error'] = $e->getMessage();

                return Row::create(
                    ...$this->arrayToEntries($rowData)
                );
            }
        });
    }

    private function arrayToEntries(array $data): array
    {
        $entries = [];

        foreach ($data as $key => $value) {
            $entries[] = $this->createEntry($key, $value);
        }

        return $entries;
    }

    private function createEntry(string $key, mixed $value): Entry
    {
        return match (true) {
            is_array($value) => new JsonEntry($key, Json::fromArray($value)),
            is_int($value) => new IntegerEntry($key, $value),
            is_float($value) => new FloatEntry($key, $value),
            is_bool($value) => new BooleanEntry($key, $value),
            $value === null => StringEntry::fromNull($key),
            $value instanceof DateTimeInterface => new DateTimeEntry($key, $value),
            default => new StringEntry($key, (string) $value),
        };
    }
}
