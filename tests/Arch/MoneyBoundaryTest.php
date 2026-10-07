<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;

arch('Brick classes are used only inside the Money namespace')
    ->expect('Brick')
    ->toOnlyBeUsedIn('App\Domain\Shared\Money');

arch('the rounding mode is used only by the Money class')
    ->expect('Brick\Math\RoundingMode')
    ->toOnlyBeUsedIn('App\Domain\Shared\Money\Money');

arch('Money is a final readonly value object')
    ->expect(Money::class)
    ->toBeFinal()
    ->toBeReadonly();

it('references RoundingMode only inside the body of fromExactMinor', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Domain/Shared/Money/Money.php');
    expect($source)->toBeString();

    $tokens = PhpToken::tokenize((string) $source);

    $pendingName = null;
    $depth = 0;
    /** @var list<array{name: string, depth: int}> $stack */
    $stack = [];
    /** @var list<string> $enclosing */
    $enclosing = [];

    foreach ($tokens as $index => $token) {
        if ($token->is(T_FUNCTION)) {
            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                if ($tokens[$next]->is(T_STRING)) {
                    $pendingName = $tokens[$next]->text;
                    break;
                }
                if ($tokens[$next]->is('(')) {
                    break;
                }
            }

            continue;
        }

        if ($token->text === '{' || $token->text === '${') {
            $depth++;
            if ($pendingName !== null) {
                $stack[] = ['name' => $pendingName, 'depth' => $depth];
                $pendingName = null;
            }

            continue;
        }

        if ($token->text === '}') {
            if ($stack !== [] && $stack[array_key_last($stack)]['depth'] === $depth) {
                array_pop($stack);
            }
            $depth--;

            continue;
        }

        if ($token->text === ';') {
            $pendingName = null;
        }

        $follower = $tokens[$index + 1] ?? null;
        if ($token->is(T_STRING) && $token->text === 'RoundingMode' && $follower?->is(T_DOUBLE_COLON)) {
            $enclosing[] = $stack === [] ? '(outside any function)' : $stack[array_key_last($stack)]['name'];
        }
    }

    expect($enclosing)->not->toBeEmpty()
        ->and(array_unique($enclosing))->toBe(['fromExactMinor']);
});
