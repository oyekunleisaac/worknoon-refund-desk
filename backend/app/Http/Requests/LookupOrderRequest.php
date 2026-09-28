<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LookupOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'order_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
        ];
    }
}
