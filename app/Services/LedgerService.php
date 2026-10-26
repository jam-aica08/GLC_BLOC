<?php

namespace App\Services;

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
     * Append many approved financial records in one go (data imports): one
     * lock, the hashes chained in order, rows inserted in batches. Each
     * record is hashed exactly as createEntry() would hash it.
     *
     * @param  list<array> $records financial_records rows, in ledger order
     * @return int entries added
     */
    public function appendMany(array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $db = db_connect();
        $lockResult = $db->query("SELECT GET_LOCK('blockchain_ledger_append', 10) AS locked")->getRowArray();
        if (empty($lockResult['locked'])) {
            throw new \RuntimeException('LedgerService: could not acquire ledger append lock.');
        }

        try {
            $latest = $db->table('blockchain_ledger')
                         ->select('transaction_hash, ledger_sequence')
                         ->orderBy('ledger_sequence', 'DESC')
                         ->limit(1)
                         ->get()
                         ->getRowArray();

            $previousHash = $latest['transaction_hash'] ?? null;
            $sequence     = $latest ? (int) $latest['ledger_sequence'] : 0;
            $now          = date('Y-m-d H:i:s');
            $rows         = [];

            foreach ($records as $record) {
                $hash   = hash('sha256', $this->buildCanonicalPayload($record) . ($previousHash ?? ''));
                $rows[] = [
                    'financial_record_id' => (int) $record['financial_record_id'],
                    'transaction_hash'    => $hash,
                    'previous_hash'       => $previousHash,
                    'ledger_sequence'     => ++$sequence,
                    'recorded_at'         => $now,
                    'verification_status' => 'Valid',
                ];
                $previousHash = $hash;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                $db->table('blockchain_ledger')->insertBatch($chunk);
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('blockchain_ledger_append')");
        }

        return count($rows);
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
        $afterBreak   = [];     // ledger_ids after the first failure, marked in one update

        // The records behind the entries, fetched 500 at a time instead of one
        // query per entry (the ledger grows with every transaction).
        $records = [];
        foreach (array_chunk(array_unique(array_map('intval', array_column($entries, 'financial_record_id'))), 500) as $ids) {
            foreach ($db->table('financial_records')->whereIn('financial_record_id', $ids)->get()->getResultArray() as $row) {
                $records[(int) $row['financial_record_id']] = $row;
            }
        }

        foreach ($entries as $entry) {
            $checked++;
            $seq      = (int) $entry['ledger_sequence'];
            $ledgerId = (int) $entry['ledger_id'];

            // Once the chain is broken, every subsequent entry is unreliable
            if ($chainBroken) {
                $afterBreak[] = $ledgerId;
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
                // First entry: previous_hash must be empty (null, or the all-zero
                // genesis value written by the original sample data).
                if ($storedPrevHash !== null && $storedPrevHash !== self::GENESIS_HASH) {
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
            $record            = $records[$financialRecordId] ?? null;

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

            if (! hash_equals($computedHash, (string) $entry['transaction_hash'])
                && ! ($seq === 1 && $storedPrevHash === self::GENESIS_HASH
                      && hash_equals($this->legacyGenesisHash($record), (string) $entry['transaction_hash']))) {
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

            // Entry is valid; clear a flag left by an earlier check.
            if (($entry['verification_status'] ?? 'Valid') !== 'Valid') {
                $db->table('blockchain_ledger')->where('ledger_id', $ledgerId)->update(['verification_status' => 'Valid']);
            }
            $valid++;
            $prevHash     = $entry['transaction_hash'];
            $prevSequence = $seq;
        }

        foreach (array_chunk($afterBreak, 500) as $ids) {
            $db->table('blockchain_ledger')->whereIn('ledger_id', $ids)->update(['verification_status' => 'Tampered']);
        }

        // --- Check 4: every approved record is in the chain -------------------
        // A row added straight to financial_records breaks no hash, yet it
        // counts toward equity and loan limits; so does an approval whose
        // ledger write failed (approveRecord only logs that).
        $missingIds = array_map('intval', array_column($db->table('financial_records fr')
            ->select('fr.financial_record_id')
            ->join('blockchain_ledger bl', 'bl.financial_record_id = fr.financial_record_id', 'left')
            ->where('fr.approval_status', 'Approved')
            ->where('bl.ledger_id IS NULL')
            ->orderBy('fr.financial_record_id', 'ASC')
            ->get()->getResultArray(), 'financial_record_id'));

        return [
            'checked'       => $checked,
            'valid'         => $valid,
            'tampered'      => $tampered,
            'missing'       => count($missingIds),
            'missing_ids'   => array_slice($missingIds, 0, 20),
            'result'        => ($tampered > 0 || $missingIds !== []) ? 'FAIL' : 'PASS',
            'first_failure' => $firstFailure,
            'verified_at'   => date('Y-m-d H:i:s'),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** previous_hash used by the original sample genesis entry. */
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Hash format used by the original sample data (GlcBlocSeeder) for the
     * genesis entry, before the canonical v1 payload existed.
     */
    private function legacyGenesisHash(array $record): string
    {
        $data = json_encode([
            'financial_record_id' => (int) $record['financial_record_id'],
            'account_id'          => (int) $record['account_id'],
            'amount'              => (float) $record['amount'],
            'record_type'         => (string) $record['record_type'],
            'transaction_date'    => substr((string) $record['transaction_date'], 0, 10),
        ]);

        return hash('sha256', $data . self::GENESIS_HASH);
    }

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
