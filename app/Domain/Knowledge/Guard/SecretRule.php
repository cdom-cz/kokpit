<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Guard;

/**
 * The rule that produced a finding. The backing value is the stable key of the Czech label under
 * knowledge.guard.rules.{value}. Every rule whose shape is unambiguous is blocking (D-01); only the two
 * heuristic rules are ambiguous. Every Stripe key blocks, test mode included (owner ruling R-18-04-1).
 */
enum SecretRule: string
{
    case PemPrivateKey = 'pem_private_key';
    case AwsAccessKey = 'aws_access_key';
    case StripeSecret = 'stripe_secret';
    case StripeTestKey = 'stripe_test_key';
    case GithubToken = 'github_token';
    case Jwt = 'jwt';
    case UrlCredentials = 'url_credentials';
    case ScanFailed = 'scan_failed';
    case PasswordAssignment = 'password_assignment';
    case HighEntropyString = 'high_entropy_string';

    public function severity(): Severity
    {
        return match ($this) {
            self::PasswordAssignment, self::HighEntropyString => Severity::Ambiguous,
            default => Severity::Blocking,
        };
    }
}
