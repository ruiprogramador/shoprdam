<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewKycRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            // 'notes'  => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->action === 'reject')],
            'notes'  => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('action') === 'reject')],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'The review action is required.',
            'action.in' => 'The action must be either approve or reject.',
            'notes.required_if' => 'The notes field is required when rejecting a KYC.',
            'notes.max' => 'The notes field must not exceed 1000 characters.',
        ];
    }
}
