<?php

namespace App\Support\Mail;

/**
 * A list of email addresses as people type them: one or many, separated by
 * commas, semicolons or line breaks.
 *
 * The send endpoints carry CC and BCC as one string (it is what the dialogs
 * edit and what the mailables log), the customer record keeps them as an
 * array, and the mailer wants an array. This turns any of those into the
 * other, so every caller splits and joins the same way.
 */
final class RecipientList
{
    /**
     * Split, trim and de-duplicate (case-insensitively, first spelling wins).
     *
     * @return list<string>
     */
    public static function parse(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $parts = is_array($value)
            ? $value
            : preg_split('/[,;\r\n]+/', (string) $value);

        $addresses = [];
        $seen = [];

        foreach ($parts as $part) {
            if (! is_scalar($part)) {
                continue;
            }

            $address = trim((string) $part);
            $key = mb_strtolower($address);

            if ($address === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $addresses[] = $address;
        }

        return $addresses;
    }

    /**
     * The list as the send dialogs show it, or null when it is empty.
     */
    public static function format(mixed $value): ?string
    {
        $addresses = self::parse($value);

        return $addresses === [] ? null : implode(', ', $addresses);
    }

    /**
     * Both lists merged, for a send that adds its own addresses to the
     * customer's saved ones.
     */
    public static function merge(mixed ...$values): ?string
    {
        return self::format(array_merge(...array_map(self::parse(...), $values)));
    }
}
