<?php
/**
 * Bulk student intake from a CSV file.
 *
 * Split into three phases on purpose, because they have very different costs
 * and very different failure modes:
 *
 *   parse()                  reads and validates the file. Reads the database,
 *                            never writes to it.
 *   assignAdmissionNumbers() fills in the numbers the file left blank, so the
 *                            preview shows exactly what will be created.
 *   prepare()                generates and hashes the passwords. The slow
 *                            phase, and it holds no transaction while it runs.
 *   commit()                 writes every row inside one short transaction.
 *
 * Hashing 250 passwords costs roughly 30 seconds on a school-grade machine.
 * Doing that inside a transaction would hold locks open for the whole of it,
 * so prepare() finishes all the hashing first and commit() then does nothing
 * but inserts.
 */
class StudentImport extends Model
{
    protected string $table = 'users';

    // A 250-row file is the stated target; the headroom is for a school that
    // pastes in a whole year group at once. Past this the admin splits the
    // file, which is better than a request that runs for minutes.
    public const MAX_ROWS  = 500;
    public const MAX_BYTES = 1048576;   // 1 MB. A 500-row CSV is well under 50 KB.

    // Ambiguous glyphs are left out: no I or 1, no O or 0. These passwords are
    // read off a printed slip by a teenager and typed into a browser, and an
    // initial credential that cannot be typed is a support call, not security.
    private const PASSWORD_ALPHABET   = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const PASSWORD_GROUPS     = 3;
    private const PASSWORD_GROUP_LEN  = 4;

    private const ADMISSION_PREFIX = 'ADM';

    /**
     * Read the file and validate every row.
     *
     * Returns ['rows' => valid rows, 'errors' => [['line' => n, 'message' => s]]].
     * Writes nothing. A bad row is reported and skipped while the rest of the
     * file is still previewed, so an admin fixes ten typos in one pass rather
     * than discovering them one upload at a time.
     */
    public function parse(string $path): array
    {
        $rows   = [];
        $errors = [];

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['rows' => [], 'errors' => [['line' => 0, 'message' => 'The file could not be read.']]];
        }

        $classMap   = $this->classMap();
        $taken      = $this->existingAdmissionNumbers();
        $seenInFile = [];

        $lineNo   = 0;
        $dataRows = 0;

        while (($cells = fgetcsv($handle)) !== false) {
            $lineNo++;

            // fgetcsv hands back [null] for a blank line.
            if ($cells === [null]) {
                continue;
            }

            $cells = array_map(static function ($c) { return trim((string) $c); }, $cells);

            // Strip a UTF-8 BOM off the very first cell. Excel writes one, and
            // without this the first header cell never matches "name".
            if ($lineNo === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }

            if (implode('', $cells) === '') {
                continue;   // wholly blank row
            }

            // Optional header row, recognised and skipped.
            if ($lineNo === 1 && strtolower($cells[0]) === 'name') {
                continue;
            }

            $dataRows++;
            if ($dataRows > self::MAX_ROWS) {
                $errors[] = [
                    'line'    => $lineNo,
                    'message' => 'File has more than ' . self::MAX_ROWS
                               . ' rows. Split it and import in batches.',
                ];
                break;
            }

            $name        = $cells[0] ?? '';
            $classRaw    = $cells[1] ?? '';
            $admissionNo = mb_strtoupper($cells[2] ?? '');

            $rowErrors = [];

            if ($name === '') {
                $rowErrors[] = 'name is missing';
            } elseif (mb_strlen($name) > 100) {
                $rowErrors[] = 'name is longer than 100 characters';
            }

            $classKey = self::normaliseClassKey($classRaw);
            $classId  = $classMap[$classKey]['id'] ?? null;

            if ($classRaw === '') {
                $rowErrors[] = 'class is missing';
            } elseif ($classId === null) {
                $rowErrors[] = sprintf('class "%s" is not a class in this school', $classRaw);
            }

            if ($admissionNo !== '') {
                if (!preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,29}$/', $admissionNo)) {
                    $rowErrors[] = sprintf('admission number "%s" is not a valid format', $admissionNo);
                } elseif (isset($taken[$admissionNo])) {
                    $rowErrors[] = sprintf('admission number "%s" already belongs to someone', $admissionNo);
                } elseif (isset($seenInFile[$admissionNo])) {
                    $rowErrors[] = sprintf(
                        'admission number "%s" appears twice in this file (already on line %d)',
                        $admissionNo,
                        $seenInFile[$admissionNo]
                    );
                }
            }

            if ($rowErrors !== []) {
                $errors[] = ['line' => $lineNo, 'message' => implode('; ', $rowErrors)];
                continue;
            }

            if ($admissionNo !== '') {
                $seenInFile[$admissionNo] = $lineNo;
            }

            $rows[] = [
                'line'         => $lineNo,
                'full_name'    => $name,
                'class_id'     => $classId,
                'class_label'  => $classMap[$classKey]['label'],
                'admission_no' => $admissionNo,   // '' means "generate one"
            ];
        }

        fclose($handle);

        if ($rows === [] && $errors === []) {
            $errors[] = ['line' => 0, 'message' => 'The file contained no rows.'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Fill in the missing admission numbers, continuing the ADM/<year>/NNNN
     * sequence already in the table and skipping anything the file supplies.
     */
    public function assignAdmissionNumbers(array $rows): array
    {
        $prefix = self::ADMISSION_PREFIX . '/' . date('Y') . '/';

        $taken = $this->existingAdmissionNumbers();
        foreach ($rows as $r) {
            if ($r['admission_no'] !== '') {
                $taken[$r['admission_no']] = true;
            }
        }

        $next = 0;
        foreach (array_keys($taken) as $existing) {
            if (strncmp($existing, $prefix, strlen($prefix)) === 0) {
                $tail = substr($existing, strlen($prefix));
                if (ctype_digit($tail)) {
                    $next = max($next, (int) $tail);
                }
            }
        }

        foreach ($rows as &$r) {
            if ($r['admission_no'] !== '') {
                $r['generated'] = false;
                continue;
            }

            do {
                $next++;
                $candidate = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            } while (isset($taken[$candidate]));

            $taken[$candidate] = true;
            $r['admission_no'] = $candidate;
            $r['generated']    = true;
        }
        unset($r);

        return $rows;
    }

    /**
     * Generate a password per row and hash it. The slow phase, deliberately
     * outside any transaction.
     *
     * set_time_limit is reset on every row rather than once up front, so the
     * budget cannot run out part way through a long file whatever
     * max_execution_time the host happens to be set to.
     */
    public function prepare(array $rows): array
    {
        foreach ($rows as &$r) {
            set_time_limit(30);
            $r['password']      = self::generatePassword();
            $r['password_hash'] = password_hash($r['password'], PASSWORD_DEFAULT);
        }
        unset($r);

        return $rows;
    }

    /**
     * Write every row in one transaction. All or nothing: a half-imported year
     * group, with no record of which half succeeded, is worse than a failed
     * import the admin can simply retry.
     *
     * Uniqueness is re-checked here rather than trusted from the preview,
     * because another admin may have taken a number in between. The UNIQUE
     * index is the real guard; this only turns it into a readable message.
     *
     * Every row is stamped with $batchId, which is what makes the import
     * undoable afterwards. See ImportBatch.
     *
     * @return array{created:int, error:?string}
     */
    public function commit(array $rows, string $batchId): array
    {
        $taken = $this->existingAdmissionNumbers();
        foreach ($rows as $r) {
            if (isset($taken[$r['admission_no']])) {
                return [
                    'created' => 0,
                    'error'   => sprintf(
                        'Admission number %s was taken by someone else while you were reviewing '
                        . 'the preview. Nothing was imported. Upload the file again to start over.',
                        $r['admission_no']
                    ),
                ];
            }
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO users
                    (full_name, email, password_hash, role, admission_no, class_id, import_batch_id)
                 VALUES (?, NULL, ?, 'student', ?, ?, ?)"
            );

            foreach ($rows as $r) {
                $stmt->execute([
                    $r['full_name'],
                    $r['password_hash'],
                    $r['admission_no'],
                    $r['class_id'],
                    $batchId,
                ]);
            }

            $this->db->commit();

            return ['created' => count($rows), 'error' => null];
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Never echo the driver message: it can carry row data.
            error_log('Student import failed: ' . $e->getMessage());

            return [
                'created' => 0,
                'error'   => 'The import failed and nothing was written. Check the file for a '
                           . 'duplicate admission number, then try again.',
            ];
        }
    }

    // ---- helpers ------------------------------------------------------------

    // "ss3 a", "SS3-A" and "SS3A" all name the same class.
    public static function normaliseClassKey(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    // Real classes only, keyed by normalised label. The placeholder is excluded:
    // an import must never file a new student under it.
    private function classMap(): array
    {
        $map = [];

        foreach ((new SchoolClass())->selectable() as $c) {
            $label = SchoolClass::labelFor($c);
            $map[self::normaliseClassKey($label)] = ['id' => (int) $c['id'], 'label' => $label];
        }

        return $map;
    }

    // Every admission number in use, upper-cased, as a lookup set.
    private function existingAdmissionNumbers(): array
    {
        $taken = [];

        $rows = $this->query("SELECT admission_no FROM users WHERE admission_no IS NOT NULL")->fetchAll();
        foreach ($rows as $r) {
            $taken[mb_strtoupper($r['admission_no'])] = true;
        }

        return $taken;
    }

    // Grouped for legibility on a printed slip: K7M4-P2QX-9RTB.
    // 12 characters from a 31-glyph alphabet is a little under 60 bits. The
    // app has no password-change screen, so this is the credential the student
    // keeps until an admin resets it, and it is sized for that rather than for
    // a value they will replace on first sign-in.
    public static function generatePassword(): string
    {
        $alphabet = self::PASSWORD_ALPHABET;
        $max      = strlen($alphabet) - 1;
        $groups   = [];

        for ($g = 0; $g < self::PASSWORD_GROUPS; $g++) {
            $chunk = '';
            for ($i = 0; $i < self::PASSWORD_GROUP_LEN; $i++) {
                $chunk .= $alphabet[random_int(0, $max)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }
}
