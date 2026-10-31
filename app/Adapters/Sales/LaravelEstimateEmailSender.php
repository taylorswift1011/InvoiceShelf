<?php

namespace App\Adapters\Sales;

use App\Domains\Sales\Contracts\EstimateEmailSender;
use App\Domains\Sales\Mail\SendEstimateMail;
use App\Support\Mail\RecipientList;
use Illuminate\Support\Facades\Mail;

class LaravelEstimateEmailSender implements EstimateEmailSender
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

        $mail->send(new SendEstimateMail($data));
    }
}
