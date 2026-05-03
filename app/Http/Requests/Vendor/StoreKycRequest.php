<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Gender;
use Illuminate\Validation\Rule;

class StoreKycRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only authorize if the user is a vendor and doesn't have an exist
        return $this->user()->canSubmitKyc();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name'         => ['required', 'string', 'max:255', 'regex:/^[\pL\s\-]+$/u'],
            'date_of_birth'     => ['required', 'date', 'before:-18 years'],
            'gender_id'         => ['required', 'exists:genders,id'],
            'gender_other'      => array_filter([
                'nullable', 'string', 'max:255',
                $this->customGenderId() ? 'required_if:gender_id,' . $this->customGenderId() : null,
            ]),
            'country_id'        => ['required', 'exists:countries,id'],
            // 'state_id'          => ['required', Rule::exists('states', 'id')->where('country_id', $this->country_id)],
            // 'city_id'           => ['required', Rule::exists('cities', 'id')->where('state_id', $this->state_id)],
            'state_id'          => ['required', Rule::exists('states', 'id')->where('country_id', $this->input('country_id'))],
            'city_id'           => ['required', Rule::exists('cities', 'id')->where('state_id', $this->input('state_id'))],
            'address_line1'     => ['required', 'string', 'max:255'],
            'address_line2'     => ['nullable', 'string', 'max:255'],
            'postal_code'       => ['required', 'string', 'max:20'],
            
            'documents'        => ['required', 'array', 'min:1'],
            'documents.*.document_type_id' => ['required', 'exists:document_types,id'],
            'documents.*.document_side_id' => ['nullable', 'exists:document_sides,id'],
            'documents.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // Max 5MB
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before' => 'You must be at least 18 years old to submit KYC.',
            'gender_other.required_if' => 'The gender other field is required when gender is set to custom.',
            'documents.required' => 'At least one document is required.',
            'documents.*.document_type_id.required' => 'Each document must have a document type.',
            'documents.*.file.mimes' => 'Each document file must be a JPG, JPEG, PNG, or PDF.',
            'documents.*.file.max' => 'Each document file must not exceed 5MB in size.',
        ];
    }

    private function customGenderId(): ?int {
        return Gender::where('slug', 'custom')->first()?->id;
    }
}
