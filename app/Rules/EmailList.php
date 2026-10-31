<?php

namespace App\Rules;

use App\Support\Mail\RecipientList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a list of email addresses: a string separated by commas,
 * semicolons or line breaks, or an array. Every address must pass
 * {@see IdnEmail}, and the list is capped so a CC field cannot be used to
 * mass-mail through the company's mailer.
 */
class EmailList implements ValidationRule
{
    public const MAX_ADDRESSES = 20;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! is_string($value) && ! is_array($value)) {
            $fail(__('validation.email', ['attribute' => $attribute]));

            return;
        }

        $addresses = RecipientList::parse($value);

        if (count($addresses) > self::MAX_ADDRESSES) {
            $fail(__('validation.max.array', ['attribute' => $attribute, 'max' => self::MAX_ADDRESSES]));

            return;
        }

        $single = new IdnEmail;

        foreach ($addresses as $address) {
            $invalid = null;
            $single->validate($attribute, $address, function (string $message) use (&$invalid): void {
                $invalid = $message;
            });

            if ($invalid !== null) {
                $fail("{$address} is not a valid email address.");

                return;
            }
        }
    }
}
