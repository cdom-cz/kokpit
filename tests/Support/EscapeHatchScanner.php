<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds the two ways to read around the fail-closed scopes in PHP source: a
 * raw `DB::table(` query and a `withoutGlobalScopes()` call without arguments
 * (which also removes the Partner scope). Comments and strings are ignored
 * because the scan works on tokens, not on text.
 */
final class EscapeHatchScanner
{
    /**
     * @return list<array{file: string, line: int, hatch: string}>
     */
    public static function findings(string $source, string $file): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = is_array($token)
                ? ['text' => $token[1], 'line' => $token[2]]
                : ['text' => $token, 'line' => $tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']];
        }

        $findings = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            // DB::table( , also written \Illuminate\Support\Facades\DB::table(
            if (($text === 'DB' || str_ends_with($text, '\\DB'))
                && ($tokens[$i + 1]['text'] ?? '') === '::'
                && strtolower($tokens[$i + 2]['text'] ?? '') === 'table'
                && ($tokens[$i + 3]['text'] ?? '') === '(') {
                $findings[] = ['file' => $file, 'line' => $tokens[$i]['line'], 'hatch' => 'DB::table('];
            }

            // withoutGlobalScopes() with nothing between the parentheses
            if (strtolower($text) === 'withoutglobalscopes'
                && ($tokens[$i + 1]['text'] ?? '') === '('
                && ($tokens[$i + 2]['text'] ?? '') === ')') {
                $findings[] = ['file' => $file, 'line' => $tokens[$i]['line'], 'hatch' => 'withoutGlobalScopes()'];
            }
        }

        return $findings;
    }
}
