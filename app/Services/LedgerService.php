<?php

namespace App\Services;

use App\Models\FinancialRecordModel;

/**
 * LedgerService — SHA-256 hash-linked ledger integrity (Fix 4.6)
 *
 * Implements an append-only, hash-chained ledger over approved financial
 * records.  Each entry stores:
 *   - transaction_hash : SHA-256 of the canonical payload concatenated with
 *                        the previous entry's hash (null for the first entry)
 *   - previous_hash    : the transaction_hash of the immediately preceding row
 *   - ledger_sequence  : monotonically incrementing integer (no gaps)
 *
 * Fix 4.5: verification_status uses the values 'Valid' / 'Tampered' — never
 *          the legacy value 'Verified'.
 */
class LedgerService
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Append a new entry to the blockchain ledger for the given financial record.
     *
     * @param  int   $financialRecordId  PK of the approved financial_record row
     * @param  array $payload            Row data from financial_records (must contain
     *                                   financial_record_id, account_id, record_type,
     *                                   transaction_date, amount, approval_status,
     *                                   processed_by)
     * @return array ['ledger_id', 'transaction_hash', 'ledger_sequence']
     * @throws \RuntimeException on DB failure
     */
    public function createEntry(int $financialRecordId, array $payload): array
    {
        $db = db_connect();

        // Acquire advisory lock to serialise concurrent ledger appends.
        // This prevents two requests from reading the same latest sequence
        // and then both trying to insert sequence N+1.
        $lockResult = $db->query("SELECT GET_LOCK('blockchain_ledger_append', 10) AS locked")
                         ->getRowArray();
        if (empty($lockResult['locked'])) {
            throw new \RuntimeException('LedgerService: could not acquire ledger append lock.');
        }

        try {
            // Fetch the latest ledger entry for chaining
            $latest = $db->table('blockchain_ledger')
                         ->select('transaction_hash, ledger_sequence')
                         ->orderBy('ledger_sequence', 'DESC')
                         ->limit(1)
                         ->get()
                         ->getRowArray();

            $previousHash = $latest['transaction_hash'] ?? null;
            $nextSequence = $latest ? ((int) $latest['ledger_sequence'] + 1) : 1;

            // Build canonical string and compute hash
            $canonicalPayload = $this->buildCanonicalPayload($payload);
            $transactionHash  = hash('sha256', $canonicalPayload . ($previousHash ?? ''));

            $insertData = [
                'financial_record_id' => $financialRecordId,
                'transaction_hash'    => $transactionHash,
                'previous_hash'       => $previousHash,
                'ledger_sequence'     => $nextSequence,
                'recorded_at'         => date('Y-m-d H:i:s'),
                'verification_status' => 'Valid',
            ];

            $db->table('blockchain_ledger')->insert($insertData);

            if ($db->affectedRows() < 1) {
                throw new \RuntimeException('LedgerService: failed to insert ledger entry.');
            }

            $ledgerId = $db->insertID();
        } finally {
            $db->query("SELECT RELEASE_LOCK('blockchain_ledger_append')");
        }

        return [
            'ledger_id'        => (int) $ledgerId,
            'transaction_hash' => $transactionHash,
            'ledger_sequence'  => $nextSequence,
        ];
    }

    /**
     * Full end-to-end verification of the entire ledger (Fix 4.6).
     *
     * Walks all entries in sequence order, recomputing each hash from the
     * original financial_record data.  Stops the chain at the first failure
     * and marks that row 'Tampered' in the DB.
     *
     * IMPORTANT: does NOT silently recompute / overwrite hashes to hide a
     * discrepancy — tampered entries are flagged and left as-is.
     *
     * @return array {
     *   checked      : int,
     *   valid        : int,
     *   tampered     : int,
     *   result       : 'PASS'|'FAIL',
     *   first_failure: array|null  { ledger_id, sequence, failure_type },
     *   verified_at  : string (Y-m-d H:i:s)
     * }
     */
    public function verifyLedger(): array
    {
        $db = db_connect();

        $entries = $db->table('blockchain_ledger')
                      ->orderBy('ledger_sequence', 'ASC')
                      ->get()
                      ->getResultArray();

        $checked      = 0;
        $valid        = 0;
        $tampered     = 0;
        $firstFailure = null;
        $prevHash     = null;
        $prevSequence = 0;
        $chainBroken  = false;  // once true, all remaining entries are marked Tampered

        $frModel = model(FinancialRecordModel::class);

        foreach ($entries as $entry) {
            $checked++;
            $seq      = (int) $entry['ledger_sequence'];
            $ledgerId = (int) $entry['ledger_id'];

            // Once the chain is broken, every subsequent entry is unreliable
            if ($chainBroken) {
                $this->markTampered($db, $ledgerId);
                $tampered++;
                continue;
            }

            // --- Check 1: sequence continuity (no gaps) ----------------------
            $expectedSeq = $prevSequence + 1;
            if ($seq !== $expectedSeq) {
                $this->markTampered($db, $ledgerId);
                $tampered++;
                $firstFailure = [
                    'ledger_id'    => $ledgerId,
                    'sequence'     => $seq,
                    'failure_type' => 'sequence_gap',
                ];
                $chainBroken = true;
                continue;
            }

            // --- Check 2: previous_hash linkage ------------------------------
            $storedPrevHash = $entry['previous_hash'];
            if ($prevHash === null) {
                // First entry: previous_hash must be null
                if ($storedPrevHash !== null) {
                    $this->markTampered($db, $ledgerId);
                    $tampered++;
                    $firstFailure = [
                        'ledger_id'    => $ledgerId,
                        'sequence'     => $seq,
                        'failure_type' => 'broken_link',
                    ];
                    $chainBroken = true;
                    continue;
                }
            } else {
                // Subsequent entries: previous_hash must match the prior row's hash
                if (!hash_equals($prevHash, (string) $storedPrevHash)) {
                    $this->markTampered($db, $ledgerId);
                    $tampered++;
                    $firstFailure = [
                        'ledger_id'    => $ledgerId,
                        'sequence'     => $seq,
                        'failure_type' => 'broken_link',
                    ];
                    $chainBroken = true;
                    continue;
                }
            }

            // --- Check 3: recompute transaction_hash from original record ----
            $financialRecordId = (int) $entry['financial_record_id'];
            $record            = $frModel->find($financialRecordId);

            if (empty($record)) {
                // Record has been deleted — cannot recompute; treat as tampered
                $this->markTampered($db, $ledgerId);
                $tampered++;
                $firstFailure = [
                    'ledger_id'    => $ledgerId,
                    'sequence'     => $seq,
                    'failure_type' => 'hash_mismatch',
                ];
                $chainBroken = true;
                continue;
            }

            $canonicalPayload = $this->buildCanonicalPayload($record);
            $computedHash     = hash('sha256', $canonicalPayload . ($storedPrevHash ?? ''));

            if (!hash_equals($computedHash, (string) $entry['transaction_hash'])) {
                $this->markTampered($db, $ledgerId);
                $tampered++;
                $firstFailure = [
                    'ledger_id'    => $ledgerId,
                    'sequence'     => $seq,
                    'failure_type' => 'hash_mismatch',
                ];
                $chainBroken = true;
                continue;
            }

            // Entry is valid
            $valid++;
            $prevHash     = $entry['transaction_hash'];
            $prevSequence = $seq;
        }

        return [
            'checked'       => $checked,
            'valid'         => $valid,
            'tampered'      => $tampered,
            'result'        => $tampered > 0 ? 'FAIL' : 'PASS',
            'first_failure' => $firstFailure,
            'verified_at'   => date('Y-m-d H:i:s'),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a deterministic, versioned canonical string from a financial_record row.
     *
     * Field order is fixed so the same data always produces the same string.
     * Format: "v1|{financial_record_id}|{account_id}|{record_type}|{transaction_date}|{amount}|{approval_status}|{processed_by}"
     *
     * @param  array $record  Row from financial_records
     * @return string
     */
    private function buildCanonicalPayload(array $record): string
    {
        // Normalise transaction_date to a full datetime string for stability
        $transactionDate = $record['transaction_date'] ?? '';
        if (strlen($transactionDate) === 10) {
            // DATE only (Y-m-d) — append midnight so format is always Y-m-d H:i:s
            $transactionDate = $transactionDate . ' 00:00:00';
        } else {
            // Already a datetime; reformat to canonical form
            $ts              = strtotime($transactionDate);
            $transactionDate = $ts ? date('Y-m-d H:i:s', $ts) : $transactionDate;
        }

        $amount      = number_format((float) ($record['amount'] ?? 0), 2, '.', '');
        $processedBy = $record['processed_by'] ?? '';

        return implode('|', [
            'v1',
            (string) ($record['financial_record_id'] ?? ''),
            (string) ($record['account_id']          ?? ''),
            (string) ($record['record_type']          ?? ''),
            $transactionDate,
            $amount,
            (string) ($record['approval_status']     ?? ''),
            (string) $processedBy,
        ]);
    }

    /**
     * Mark a single ledger row as 'Tampered'.
     *
     * @param  \CodeIgniter\Database\BaseConnection $db
     * @param  int                                  $ledgerId
     * @return void
     */
    private function markTampered(\CodeIgniter\Database\BaseConnection $db, int $ledgerId): void
    {
        $db->table('blockchain_ledger')
           ->where('ledger_id', $ledgerId)
           ->update(['verification_status' => 'Tampered']);
    }
}
