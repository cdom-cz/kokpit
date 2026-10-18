<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

/**
 * Whether a stored URL may become the visitor's next location (T-11-28): only `https` on `stripe.com` or one of its
 * subdomains, with no user info and no port. The database CHECK of the payment link record says the same; this check
 * stands on its own in front of every redirect.
 */
final readonly class StripeUrl
{
    public static function isAllowed(?string $url): bool
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        return $host === 'stripe.com' || str_ends_with($host, '.stripe.com');
    }
}
