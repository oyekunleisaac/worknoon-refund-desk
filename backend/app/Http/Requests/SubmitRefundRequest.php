<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'order_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
            'message' => ['required', 'string', 'min:10', 'max:'.config('refund.message_max_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'message.min' => 'Please describe what went wrong in a little more detail.',
        ];
    }
}
