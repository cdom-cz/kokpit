<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Settings\Banking\Iban;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The payload of a Czech payment QR code (SPAYD 1.0, qr-platba.cz): a header and
 * KEY:VALUE pairs joined by an asterisk. Pure and static.
 *
 * The code exists only for a CZK document paid to a Czech IBAN (D-21); anything
 * else is refused so a QR can never point at a wrong account or currency. The
 * free-text message is sanitised here because it ends up inside a payment
 * instruction: an asterisk would start a new key, so it is replaced, and the
 * text is reduced to upper-case ASCII, which also keeps the QR in its dense
 * alphanumeric mode.
 */
final class Spayd
{
    /**
     * The longest message the format allows.
     */
    public const int MESSAGE_LENGTH = 60;

    /**
     * Whether a payment QR can be printed for this currency and account: CZK and
     * a well-formed Czech IBAN.
     */
    public static function supports(string $currency, ?string $iban): bool
    {
        if ($currency !== 'CZK' || $iban === null) {
            return false;
        }

        $normalised = Iban::normalise($iban);

        return str_starts_with($normalised, 'CZ') && Iban::isValid($normalised);
    }

    /**
     * Builds the SPAYD string.
     *
     * @param  string  $dueDate  Y-m-d, written as DT:YYYYMMDD
     *
     * @throws InvalidArgumentException when the currency is not CZK, the IBAN is not a Czech one, the amount is not positive or longer than ten characters, the variable symbol is not 1 to 10 digits or the due date is not a calendar date
     */
    public static function forPayment(string $iban, Money $amount, string $variableSymbol, string $dueDate, string $message): string
    {
        $account = Iban::normalise($iban);

        if (! self::supports($amount->currency, $account)) {
            throw new InvalidArgumentException('A payment QR needs the currency CZK and a Czech IBAN.');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A payment QR needs a positive amount.');
        }

        $major = $amount->toMajor();

        if (strlen($major) > 10) {
            throw new InvalidArgumentException('The amount is longer than ten characters.');
        }

        if (preg_match('/^[0-9]{1,10}$/D', $variableSymbol) !== 1) {
            throw new InvalidArgumentException('The variable symbol must be 1 to 10 digits.');
        }

        $date = self::compactDate($dueDate);

        $fields = [
            'ACC:'.$account,
            'AM:'.$major,
            'CC:CZK',
            'X-VS:'.$variableSymbol,
            'DT:'.$date,
        ];

        $text = self::sanitiseMessage($message);

        if ($text !== '') {
            $fields[] = 'MSG:'.$text;
        }

        return 'SPD*1.0*'.implode('*', $fields);
    }

    /**
     * Reduces a free text to what a SPAYD message may safely carry: ASCII
     * letters and digits, a space and a few punctuation marks, upper-cased and
     * cut to 60 characters. No asterisk survives.
     */
    public static function sanitiseMessage(string $message): string
    {
        // No iconv //TRANSLIT: musl (Alpine) rejects the suffix and glibc answers "?" under the C locale.
        $upper = strtoupper(Str::ascii($message));

        $safe = preg_replace('/[^A-Z0-9 $%+\-.\/:]+/', ' ', $upper) ?? '';
        $collapsed = trim((string) preg_replace('/ {2,}/', ' ', $safe));

        return rtrim(substr($collapsed, 0, self::MESSAGE_LENGTH));
    }

    private static function compactDate(string $dueDate): string
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $dueDate, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('The due date must be a calendar date written Y-m-d.');
        }

        return $parts[1].$parts[2].$parts[3];
    }
}
