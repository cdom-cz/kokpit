<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Mail;

use App\Domain\Settings\Settings\SupplierSettings;
use Illuminate\Mail\Mailables\Address;

/**
 * The sender of the billing e-mails: the configured from address, shown under the company name of the supplier
 * settings instead of the application name.
 */
final class SupplierSender
{
    public static function address(): Address
    {
        return new Address(
            (string) config('mail.from.address'),
            app(SupplierSettings::class)->company_name,
        );
    }
}
