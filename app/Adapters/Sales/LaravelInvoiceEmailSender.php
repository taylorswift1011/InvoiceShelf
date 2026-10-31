<?php

namespace App\Adapters\Sales;

use App\Domains\Sales\Contracts\InvoiceEmailSender;
use App\Domains\Sales\Mail\SendCreditNoteMail;
use App\Domains\Sales\Mail\SendInvoiceMail;
use App\Support\Mail\RecipientList;
use Illuminate\Support\Facades\Mail;

class LaravelInvoiceEmailSender implements InvoiceEmailSender
{
    public function send(array $data, bool $creditNote): void
    {
        $mail = Mail::to($data['to']);

        // CC and BCC arrive as one typed list ("a@x.com, b@y.com")
        if ($cc = RecipientList::parse($data['cc'] ?? null)) {
            $mail->cc($cc);
        }

        if ($bcc = RecipientList::parse($data['bcc'] ?? null)) {
            $mail->bcc($bcc);
        }

        $mail->send($creditNote
            ? new SendCreditNoteMail($data)
            : new SendInvoiceMail($data));
    }
}
