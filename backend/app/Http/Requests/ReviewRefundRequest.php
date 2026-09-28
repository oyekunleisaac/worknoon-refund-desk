<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,deny'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            'reviewer' => ['required', 'string', 'max:80'],
        ];
    }
}
