<?php

namespace App\Domains\Reporting\Http\Controllers\Company;

use App\Domains\Contacts\Models\Customer;
use App\Domains\Reporting\Http\Requests\SendCustomerStatementRequest;
use App\Domains\Reporting\Mail\SendCustomerStatementMail;
use App\Domains\Reporting\Queries\CustomerStatementQuery;
use App\Domains\Reporting\Rendering\CustomerStatementPdfRenderer;
use App\Platform\Http\Controller;
use App\Platform\Mail\Contracts\MailConfigurator;
use App\Support\Mail\RecipientList;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class SendCustomerStatementController extends Controller
{
    public function __construct(
        private readonly CustomerStatementQuery $customerStatementQuery,
        private readonly CustomerStatementPdfRenderer $customerStatementPdfRenderer,
        private readonly MailConfigurator $mailConfigurator,
    ) {}

    public function __invoke(SendCustomerStatementRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->authorize('view report', $customer->company);

        $type = $request->validated('type');

        $statement = $this->customerStatementQuery->statement(
            $customer,
            $type,
            Carbon::createFromFormat('Y-m-d', $request->validated('from_date')),
            Carbon::createFromFormat('Y-m-d', $request->validated($type === CustomerStatementQuery::TYPE_OUTSTANDING ? 'as_of' : 'to_date')),
            PHP_INT_MAX,
        );
        $pdf = $this->customerStatementPdfRenderer->render($statement);

        $this->mailConfigurator->applyCompanyConfig($customer->company_id);

        $mail = Mail::to($request->validated('to'));
        if ($cc = RecipientList::parse($request->validated('cc'))) {
            $mail->cc($cc);
        }
        if ($bcc = RecipientList::parse($request->validated('bcc'))) {
            $mail->bcc($bcc);
        }

        $mail->send(new SendCustomerStatementMail([
            ...$request->safe()->only(['to', 'cc', 'bcc', 'subject', 'body']),
            'from' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'customer' => $customer,
            'pdf' => $pdf,
            'filename' => __('Customer Statement').' '.$customer->name.'.pdf',
        ]));

        return response()->json(['success' => true]);
    }
}
