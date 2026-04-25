<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Gender;
use Illuminate\Validation\Rule;

class UpdateKycRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $kyc = $this->user()->kyc;
        return $kyc !== null && $kyc->canBeEdited();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name'         => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[\pL\s\-]+$/u'],
            'gender_id'         => ['sometimes', 'required', 'exists:genders,id'],
            'gender_other'      => array_filter([
                'nullable', 'string', 'max:255',
                $this->customGenderId() ? 'required_if:gender_id,' . $this->customGenderId() : null,
            ]),
            'country_id'        => ['sometimes', 'required', 'exists:countries,id'],
            // 'state_id'          => ['sometimes', 'required', Rule::exists('states', 'id')->where('country_id', $this->country_id)],
            // 'city_id'           => ['sometimes', 'required', Rule::exists('cities', 'id')->where('state_id', $this->state_id)],
            'state_id'          => ['sometimes', 'required', Rule::exists('states', 'id')->where('country_id', $this->input('country_id'))],
            'city_id'           => ['sometimes', 'required', Rule::exists('cities', 'id')->where('state_id', $this->input('state_id'))],
            'address_line1'     => ['sometimes', 'required', 'string', 'max:255'],
            'address_line2'     => ['nullable', 'string', 'max:255'],
            'postal_code'       => ['sometimes', 'required', 'string', 'max:20'],

            'documents'        => ['sometimes', 'array', 'min:1'],
            'documents.*.document_type_id' => ['required_with:documents', 'exists:document_types,id'],
            'documents.*.document_side_id' => ['nullable', 'exists:document_sides,id'],
            'documents.*.file' => ['required_with:documents', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // Max 5MB
        ];
    }

    public function messages(): array
    {
        return [
            'gender_id.required' => 'The gender field is required when updating KYC.',
            'gender_other.required_if' => 'The gender other field is required when gender is set to custom.',
            'country_id.required' => 'The country field is required when updating KYC.',
            'state_id.required' => 'The state field is required when updating KYC.',
            'city_id.required' => 'The city field is required when updating KYC.',
            'address_line1.required' => 'The address line 1 field is required when updating KYC.',
            'postal_code.required' => 'The postal code field is required when updating KYC.',
            'documents.required' => 'At least one document is required when updating KYC.',
            'documents.*.document_type_id.required_with' => 'Each document must have a document type when updating KYC.',
            // 'documents.*.side_id.required' => 'Each document must have a side specified when updating KYC.', --- IGNORE ---
            // 'documents.*.file.required' => 'Each document must have a file uploaded when updating KYC.', --- IGNORE ---
            'documents.*.file.mimes' => 'Each document file must be a JPG, JPEG, PNG, or PDF when updating KYC.',
            'documents.*.file.max' => 'Each document file must not exceed 5MB in size when updating KYC.',
        ];
    }

    private function customGenderId(): ?int
    {
        return Gender::where('slug', 'custom')->first()?->id;
    }
}
