<?php

namespace App\Domains\Sales\Http\Requests;

use App\Rules\EmailList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The envelope of an estimate mail: who it goes to, what it says, and the
 * optional carbon copies.
 */
class SendEstimatesRequest extends FormRequest
{
    /**
     * The send ability is checked in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => 'required',
            'body' => 'required',
            'from' => 'required',
            'to' => 'required',
            // One or more addresses, separated by commas, semicolons or line breaks
            'cc' => ['nullable', new EmailList],
            'bcc' => ['nullable', new EmailList],
        ];
    }
}
