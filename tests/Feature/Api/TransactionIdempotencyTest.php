<?php

declare(strict_types=1);

use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\Finance\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;

/*
 * AP-05, FI-01, D-04: the optional Idempotency-Key on both transaction create routes. The middleware is the one
 * of the time entry creates; these tests prove the wiring of the two routes. Real Sanctum tokens; every value is
 * fictional.
 */

/**
 * A valid single create body; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function txIdemBody(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => FinanceApiFixtures::softwareCategory()->id,
        'amount_minor' => 123450,
        'currency' => 'CZK',
        'description' => 'Example licence',
        ...$overrides,
    ];
}

/**
 * Arranges a claim row of the token for the exact request `postJson` will send: the hash is the sha256 of method,
 * path and raw body, and `postJson` sends json_encode of the array.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $attributes
 */
function txIdemClaim(string $plainToken, string $key, string $path, array $body, array $attributes = []): IdempotencyKey
{
    $row = new IdempotencyKey;
    $row->forceFill(array_merge([
        'token_id' => explode('|', $plainToken, 2)[0],
        'key' => $key,
        'request_hash' => hash('sha256', "POST\n{$path}\n".json_encode($body)),
        'locked_at' => now(),
        'expires_at' => now()->addHours(24),
    ], $attributes))->save();

    return $row;
}

describe('POST /api/v1/transactions', function (): void {
    it('creates one transaction for the same key and body sent twice, and replays the stored 201 byte for byte', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0201'];

        $first = $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(), $headers)->assertCreated();

        // A real client is a new request: the guard of the test keeps the user of the first request.
        Auth::forgetGuards();

        $second = $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(), $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        expect($second->getContent())->toBe($first->getContent())
            ->and($first->headers->has('Idempotent-Replayed'))->toBeFalse()
            ->and($first->headers->has('Location'))->toBeTrue()
            ->and($second->headers->has('Location'))->toBeFalse()
            ->and(Transaction::query()->count())->toBe(1)
            ->and(IdempotencyKey::query()->count())->toBe(1);
    });

    it('refuses the same key with a different body with 422 idempotency_key_reused and saves nothing new', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0202'];

        $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(), $headers)->assertCreated();
        Auth::forgetGuards();

        $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(['amount_minor' => 999]), $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_reused')
            ->assertHeaderMissing('Idempotent-Replayed');

        expect(Transaction::query()->count())->toBe(1);
    });

    it('answers 409 idempotency_in_progress while another request holds a fresh claim', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $body = txIdemBody();
        txIdemClaim($token, 'demo-key-0203', 'api/v1/transactions', $body, ['locked_at' => now()->subSeconds(10)]);

        $this->withToken($token)->postJson('/api/v1/transactions', $body, ['Idempotency-Key' => 'demo-key-0203'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_in_progress');

        expect(Transaction::query()->count())->toBe(0)
            ->and(IdempotencyKey::query()->count())->toBe(1);
    });

    it('leaves no key after a 422 and creates the row when the corrected request is sent with the same key', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0204'];

        $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(['amount_minor' => 0]), $headers)->assertStatus(422);

        expect(IdempotencyKey::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);

        Auth::forgetGuards();

        $retry = $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody(), $headers)->assertCreated();

        expect($retry->headers->has('Idempotent-Replayed'))->toBeFalse()
            ->and(Transaction::query()->count())->toBe(1);
    });

    it('creates a transaction on every request that carries no key', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

        $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody())->assertCreated();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/transactions', txIdemBody())->assertCreated();

        expect(Transaction::query()->count())->toBe(2)
            ->and(IdempotencyKey::query()->count())->toBe(0);
    });

    it('ignores the header on PATCH, DELETE and GET of a transaction and stores nothing for them', function (): void {
        $admin = ApiFixtures::admin();
        $transaction = FinanceApiFixtures::transaction($admin);
        $token = ApiFixtures::token($admin, ['finance:read', 'finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0205'];
        $path = '/api/v1/transactions/'.$transaction->id;

        $this->withToken($token)->getJson($path, $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        Auth::forgetGuards();
        $this->withToken($token)->patchJson($path, ['description' => 'Changed'], $headers)->assertOk();
        Auth::forgetGuards();
        // The same PATCH again is applied again: no replay and no 422 for a repeated key.
        $this->withToken($token)->patchJson($path, ['description' => 'Changed again'], $headers)
            ->assertOk()
            ->assertJsonPath('data.description', 'Changed again')
            ->assertHeaderMissing('Idempotent-Replayed');
        Auth::forgetGuards();
        $this->withToken($token)->deleteJson($path, [], $headers)->assertNoContent();

        expect(IdempotencyKey::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});

describe('POST /api/v1/transactions/batch', function (): void {
    it('replays the stored 207 byte for byte for a retried mixed batch and saves nothing new', function (): void {
        $archived = FinanceApiFixtures::softwareChild();
        DB::table('transaction_categories')->where('id', $archived->id)->update(['archived_at' => '2026-10-10 08:00:00+00']);
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0206'];
        $body = ['transactions' => [txIdemBody(['description' => 'one']), txIdemBody(['description' => 'two', 'category_id' => $archived->id]), txIdemBody(['description' => 'three'])]];

        $first = $this->withToken($token)->postJson('/api/v1/transactions/batch', $body, $headers)->assertStatus(207);
        Auth::forgetGuards();

        $second = $this->withToken($token)->postJson('/api/v1/transactions/batch', $body, $headers)
            ->assertStatus(207)
            ->assertHeader('Idempotent-Replayed', 'true');

        expect($second->getContent())->toBe($first->getContent())
            ->and($first->headers->has('Idempotent-Replayed'))->toBeFalse()
            ->and(Transaction::query()->count())->toBe(2)
            ->and(IdempotencyKey::query()->count())->toBe(1);
    });

    it('replays a stored 201 of a batch', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0207'];
        $body = ['transactions' => [txIdemBody(), txIdemBody(['amount_minor' => 5000])]];

        $first = $this->withToken($token)->postJson('/api/v1/transactions/batch', $body, $headers)->assertCreated();
        Auth::forgetGuards();
        $second = $this->withToken($token)->postJson('/api/v1/transactions/batch', $body, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        expect($second->getContent())->toBe($first->getContent())
            ->and(Transaction::query()->count())->toBe(2);
    });

    it('releases the key of a batch that saved nothing so the corrected batch with the same key is saved', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0208'];

        $this->withToken($token)->postJson('/api/v1/transactions/batch', [
            'transactions' => [txIdemBody(['amount_minor' => 0])],
        ], $headers)->assertStatus(422);

        expect(IdempotencyKey::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);

        Auth::forgetGuards();

        $retry = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
            'transactions' => [txIdemBody()],
        ], $headers)->assertCreated();

        expect($retry->headers->has('Idempotent-Replayed'))->toBeFalse()
            ->and($retry->json('saved'))->toBe(1)
            ->and(Transaction::query()->count())->toBe(1);
    });

    it('refuses the same key with a different batch body with 422 idempotency_key_reused', function (): void {
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $headers = ['Idempotency-Key' => 'demo-key-0209'];

        $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => [txIdemBody()]], $headers)->assertCreated();
        Auth::forgetGuards();

        $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => [txIdemBody(), txIdemBody(['amount_minor' => 5000])]], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_reused');

        expect(Transaction::query()->count())->toBe(1);
    });

    it('answers 409 idempotency_in_progress while another request holds a fresh claim', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $body = ['transactions' => [txIdemBody()]];
        txIdemClaim($token, 'demo-key-0210', 'api/v1/transactions/batch', $body, ['locked_at' => now()->subSeconds(10)]);

        $this->withToken($token)->postJson('/api/v1/transactions/batch', $body, ['Idempotency-Key' => 'demo-key-0210'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_in_progress');

        expect(Transaction::query()->count())->toBe(0);
    });
});
