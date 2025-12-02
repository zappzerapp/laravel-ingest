<?php

namespace LaravelIngest\DTOs;

class RowData
{
    public int $rowNumber;
    public array $originalData;

    public function __construct(array $data, int $rowNumber)
    {
        $this->originalData = $data;
        $this->rowNumber = $rowNumber;
    }
}