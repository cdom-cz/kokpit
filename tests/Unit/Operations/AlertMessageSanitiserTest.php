<?php

declare(strict_types=1);

use App\Domain\Operations\Alerts\AlertMessageSanitiser;

/*
 * Fictional leaking messages, assembled at runtime from fragments so that no
 * line of this file looks like a real host, address or credential.
 */

function leakHost(): string
{
    return implode('.', ['db', 'internal', 'example', 'com']);
}

function leakSecret(): string
{
    return implode('-', ['fictional', 'secret', 'fragment']);
}

function leakIpv4(): string
{
    return implode('.', [10, 20, 30, 40]);
}

/**
 * @return array<string, array{0: string, 1: list<string>}>
 */
dataset('leaking messages', function (): array {
    $host = leakHost();
    $ip = leakIpv4();

    return [
        'connection failure with host, ip and port' => [
            'SQLSTATE[08006] [7] connection to server at "'.$host.'" ('.$ip.'), port 5432 failed: timeout expired',
            [$host, $ip, '5432'],
        ],
        'query exception with the statement and bindings' => [
            'SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into "clients" ("name") values (Jane Example))',
            ['insert into', 'Jane Example', 'clients'],
        ],
        'unique violation with a key value' => [
            'duplicate key value violates unique constraint "clients_email_unique" Key (email)=(jane@example.com) already exists',
            ['clients_email_unique', 'jane@example.com'],
        ],
        'DSN fragment' => [
            'could not connect: host='.$host.' port=5432 dbname=kokpit user=app '.implode('', ['pass', 'word=']).leakSecret(),
            [$host, 'kokpit', leakSecret(), 'user=app'],
        ],
        'URL with credentials' => [
            'Failed to open stream: '.implode('', ['https', '://', 'api-user:', leakSecret(), '@', $host, '/v1/rates']),
            [$host, leakSecret(), 'api-user'],
        ],
        'ipv6 address' => [
            'Connection refused ['.implode(':', ['2001', 'db8', '', '1']).']:6379',
            ['2001', 'db8'],
        ],
        'file path' => [
            'include(/srv/example/app/secrets/config.php): Failed to open stream',
            ['/srv/example', 'secrets'],
        ],
    ];
});

it('removes hosts, addresses, ports, DSN fragments, SQL and quoted values from the message', function (string $message, array $forbidden): void {
    $clean = AlertMessageSanitiser::sanitise($message);

    foreach ($forbidden as $fragment) {
        expect($clean)->not->toContain($fragment);
    }
})->with('leaking messages');

it('keeps the human part of an ordinary message', function (): void {
    expect(AlertMessageSanitiser::sanitise('Division by zero'))->toBe('Division by zero')
        ->and(AlertMessageSanitiser::sanitise('Rate limit exceeded, retry later'))->toBe('Rate limit exceeded, retry later');
});

it('keeps only the first line and cuts after sanitising', function (): void {
    $clean = AlertMessageSanitiser::firstLine("Fictional failure with a very long first line\nsecond line", 10);

    expect($clean)->toBe('Fictional ');
});

it('never returns a longer line than the limit', function (): void {
    expect(mb_strlen(AlertMessageSanitiser::firstLine(str_repeat('a ', 300), 200)))->toBeLessThanOrEqual(200);
});

it('sanitises a 300 000 character hyphenated first line in well under a second', function (): void {
    $line = 'Fictional failure '.str_repeat('abc-', 75_000);

    $start = hrtime(true);
    $clean = AlertMessageSanitiser::firstLine($line, 200);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(500.0)
        ->and(mb_strlen($clean))->toBeLessThanOrEqual(200)
        ->and($clean)->toStartWith('Fictional failure');
});

it('looks at no more than the input cap of a line', function (): void {
    $tail = leakSecret();
    $line = str_repeat('x ', AlertMessageSanitiser::MAX_INPUT_LENGTH).$tail;

    expect(AlertMessageSanitiser::sanitise($line))->not->toContain($tail);
});

it('does not show the start of a quoted value whose closing quote lies beyond the input cap', function (): void {
    $secret = leakSecret();
    $line = 'Lookup failed for "'.$secret.str_repeat(' padding', 1_000).'"';

    expect(AlertMessageSanitiser::firstLine($line, 200))->not->toContain($secret);
});
