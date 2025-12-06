<?php

namespace LaravelIngest\DTOs;

class RowData
{
    public int $rowNumber;
    public readonly array $originalData;
    public array $processedData;

    public function __construct(array $data, int $rowNumber)
    {
        $this->originalData = $data;
        $this->processedData = $data;
        $this->rowNumber = $rowNumber;
    }
}