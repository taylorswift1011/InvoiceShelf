<?php

namespace App\Domains\Reporting\Http\Requests;

use App\Domains\Reporting\Queries\CustomerStatementQuery;
use App\Rules\EmailList;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendCustomerStatementRequest extends FormRequest
{
    /**
     * Left open deliberately. Whether this caller may see the account at
     * all is settled by the controller, ahead of the statement being
     * built; the shape of the payload is all that is settled here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The statement to draw up, together with the message it travels in.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([CustomerStatementQuery::TYPE_ACTIVITY, CustomerStatementQuery::TYPE_OUTSTANDING])],
            'from_date' => ['nullable', 'date_format:Y-m-d', 'required_if:type,activity'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'required_if:type,activity', 'after_or_equal:from_date'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'required_if:type,outstanding'],
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
            'to' => ['required', 'email'],
            // One or more addresses, separated by commas, semicolons or line breaks
            'cc' => ['nullable', new EmailList],
            'bcc' => ['nullable', new EmailList],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = $this->input('type', CustomerStatementQuery::TYPE_ACTIVITY);
        $today = Carbon::today();

        $this->merge([
            'type' => $type,
            'from_date' => $this->input('from_date', $today->copy()->startOfMonth()->toDateString()),
            'to_date' => $this->input('to_date', $today->toDateString()),
            'as_of' => $this->input('as_of', $today->toDateString()),
        ]);
    }
}
