<?php

namespace App\Adapters\Receivables;

use App\Domains\Receivables\Contracts\PaymentEmailSender;
use App\Domains\Receivables\Mail\SendPaymentMail;
use App\Support\Mail\RecipientList;
use Illuminate\Support\Facades\Mail;

class LaravelPaymentEmailSender implements PaymentEmailSender
{
    public function send(array $data): void
    {
        $mail = Mail::to($data['to']);

        // CC and BCC arrive as one typed list ("a@x.com, b@y.com")
        if ($cc = RecipientList::parse($data['cc'] ?? null)) {
            $mail->cc($cc);
        }

        if ($bcc = RecipientList::parse($data['bcc'] ?? null)) {
            $mail->bcc($bcc);
        }

        $mail->send(new SendPaymentMail($data));
    }
}
