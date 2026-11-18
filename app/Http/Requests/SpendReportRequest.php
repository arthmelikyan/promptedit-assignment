<?php

namespace App\Http\Requests;

use App\Data\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SpendReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_date' => $this->input('start_date', now()->utc()->subDays(29)->toDateString()),
            'end_date' => $this->input('end_date', now()->utc()->toDateString()),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:today'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'refresh' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->hasAny(['start_date', 'end_date']) && CarbonImmutable::parse($this->string('start_date')->toString())->diffInDays(CarbonImmutable::parse($this->string('end_date')->toString())) > 91) {
                    $validator->errors()->add('end_date', 'Choose a range of no more than 92 days.');
                }
            },
        ];
    }

    public function toData(): ReportFilterData
    {
        return new ReportFilterData(
            startDate: $this->validated('start_date'),
            endDate: $this->validated('end_date'),
            userId: $this->filled('user_id') ? $this->integer('user_id') : null,
            refresh: $this->boolean('refresh'),
        );
    }
}
