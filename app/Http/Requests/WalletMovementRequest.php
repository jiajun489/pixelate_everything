<?php

namespace App\Http\Requests;

use App\Models\Wallet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WalletMovementRequest extends FormRequest
{
    /**
     * Client references exclude ":" so they can never collide with system "bet:*" references.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer:strict', 'min:1', 'max:'.Wallet::MAX_AMOUNT],
            'reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/'],
        ];
    }
}
