<?php

namespace App\Services\Ledger;

use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grand livre en partie double, append-only, chaîné par hash (SHA-256).
 *
 * hash(n) = sha256( hash(n-1) | canonical(payload(n)) )
 *
 * Toute altération d'une écriture (montant, compte, ordre, suppression) casse la chaîne
 * et est détectée par verify(). Les écritures sont idempotentes sur (ref_type, ref_id, operation).
 */
class LedgerService
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @return LedgerEntry l'écriture créée, ou l'écriture existante si déjà postée (idempotent)
     */
    public function post(
        string $operation,
        string $debitAccount,
        string $creditAccount,
        int $amountCents,
        string $refType,
        string $refId,
        string $currency = 'EUR',
        ?string $campaignId = null,
        ?int $userId = null,
        ?string $gatewayEventId = null,
        array $metadata = [],
        ?CarbonImmutable $occurredAt = null,
    ): LedgerEntry {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException("Ledger amount must be > 0, got $amountCents");
        }
        if ($debitAccount === $creditAccount) {
            throw new InvalidArgumentException('Debit and credit accounts must differ');
        }

        return DB::transaction(function () use (
            $operation, $debitAccount, $creditAccount, $amountCents, $refType, $refId,
            $currency, $campaignId, $userId, $gatewayEventId, $metadata, $occurredAt
        ) {
            // Verrou exclusif sur la tête de chaîne : sérialise toutes les écritures.
            $head = DB::table('ledger_heads')->where('id', 1)->lockForUpdate()->first();

            $existing = LedgerEntry::query()
                ->where(['ref_type' => $refType, 'ref_id' => $refId, 'operation' => $operation])
                ->first();
            if ($existing) {
                return $existing;
            }

            $seq = (int) $head->last_seq + 1;
            $prevHash = $head->last_hash;
            $occurredAt ??= CarbonImmutable::now('UTC');

            $payload = [
                'seq' => $seq,
                'occurredAt' => $occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'operation' => $operation,
                'debitAccount' => $debitAccount,
                'creditAccount' => $creditAccount,
                'amountCents' => $amountCents,
                'currency' => $currency,
                'refType' => $refType,
                'refId' => $refId,
                'campaignId' => $campaignId,
                'userId' => $userId,
            ];
            $hash = self::computeHash($prevHash, $payload);

            $entry = LedgerEntry::create([
                'seq' => $seq,
                'occurred_at' => $occurredAt,
                'operation' => $operation,
                'debit_account' => $debitAccount,
                'credit_account' => $creditAccount,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'gateway_event_id' => $gatewayEventId,
                'metadata' => $metadata,
                'payload' => $payload,
                'prev_hash' => $prevHash,
                'hash' => $hash,
            ]);

            DB::table('ledger_heads')->where('id', 1)->update([
                'last_seq' => $seq,
                'last_hash' => $hash,
                'updated_at' => now(),
            ]);

            return $entry;
        });
    }

    /**
     * Rejoue toute la chaîne. Vérifie aussi que les colonnes correspondent au payload haché
     * (sinon on pourrait modifier `amount_cents` sans toucher au JSON).
     *
     * @return array{ok: bool, entries: int, broken_seq: ?int, reason: ?string}
     */
    public function verify(): array
    {
        $prev = self::GENESIS;
        $expectedSeq = 1;
        $count = 0;

        foreach (LedgerEntry::query()->orderBy('seq')->lazy(500) as $entry) {
            $count++;
            $payload = $entry->payload;

            $reason = match (true) {
                $entry->seq !== $expectedSeq => 'sequence gap',
                $entry->prev_hash !== $prev => 'prev_hash mismatch',
                $entry->hash !== self::computeHash($prev, $payload) => 'hash mismatch',
                ($payload['amountCents'] ?? null) !== $entry->amount_cents
                    || ($payload['debitAccount'] ?? null) !== $entry->debit_account
                    || ($payload['creditAccount'] ?? null) !== $entry->credit_account
                    || ($payload['operation'] ?? null) !== $entry->operation
                    || ($payload['refId'] ?? null) !== $entry->ref_id => 'columns differ from hashed payload',
                default => null,
            };

            if ($reason) {
                return ['ok' => false, 'entries' => $count, 'broken_seq' => $entry->seq, 'reason' => $reason];
            }

            $prev = $entry->hash;
            $expectedSeq++;
        }

        $head = DB::table('ledger_heads')->where('id', 1)->first();
        if ($head && ((int) $head->last_seq !== $count || $head->last_hash !== $prev)) {
            return ['ok' => false, 'entries' => $count, 'broken_seq' => $count, 'reason' => 'head does not match last entry (truncated chain?)'];
        }

        return ['ok' => true, 'entries' => $count, 'broken_seq' => null, 'reason' => null];
    }

    /** Solde d'un compte (débits - crédits), en centimes. */
    public function balance(string $account): int
    {
        $debit = (int) LedgerEntry::where('debit_account', $account)->sum('amount_cents');
        $credit = (int) LedgerEntry::where('credit_account', $account)->sum('amount_cents');

        return $debit - $credit;
    }

    /** @return list<array{account: string, debit: int, credit: int, balance: int}> */
    public function trialBalance(): array
    {
        $debits = LedgerEntry::query()->selectRaw('debit_account as account, sum(amount_cents) as total')->groupBy('debit_account')->pluck('total', 'account');
        $credits = LedgerEntry::query()->selectRaw('credit_account as account, sum(amount_cents) as total')->groupBy('credit_account')->pluck('total', 'account');

        $accounts = $debits->keys()->merge($credits->keys())->unique()->sort()->values();

        return $accounts->map(fn ($a) => [
            'account' => $a,
            'debit' => (int) ($debits[$a] ?? 0),
            'credit' => (int) ($credits[$a] ?? 0),
            'balance' => (int) ($debits[$a] ?? 0) - (int) ($credits[$a] ?? 0),
        ])->all();
    }

    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::canonical(...), $value)).']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).':'.self::canonical($v);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function computeHash(string $prevHash, array $payload): string
    {
        return hash('sha256', $prevHash.'|'.self::canonical($payload));
    }
}
