<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer'],
            'metadata' => ['nullable', 'array'],
            'metadata.color' => ['nullable', 'string'],
            'metadata.dimensions.width' => ['required', 'numeric'],
            'metadata.dimensions.height' => ['required', 'numeric'],
            'images' => ['sometimes', 'array'],
            'images.*.file' => ['required', 'image'],
            'images.*.caption' => ['nullable', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ];
    }
}
