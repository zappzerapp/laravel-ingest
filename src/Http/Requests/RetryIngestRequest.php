<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetryIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
