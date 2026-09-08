<?php
/**
 * Generate a student-import CSV for load-testing the bulk import.
 *
 *   php database/fixtures/generate_students.php            > students_250.csv
 *   php database/fixtures/generate_students.php 500        > students_500.csv
 *   php database/fixtures/generate_students.php 250 out.csv
 *
 * Deterministic on purpose: no randomness and no reference to the current
 * date, so the same row count always produces a byte-identical file. A timing
 * run this week is comparable with one from last month, and a regression in
 * the importer cannot hide behind "well, it was different data".
 *
 * The output is not committed. It is derivable from this script in a second,
 * and a 250-row CSV of fabricated names is not worth carrying in git.
 */

declare(strict_types=1);

// Deliberately larger than any single class list, so names recur across the
// file the way they do in a real Nigerian secondary school. Two students
// called "Bola Bakare" in different arms is the normal case, not the edge
// case, and it is the whole reason grading surfaces show an admission number.
$firstNames = [
    'Tunde', 'Chidinma', 'Fatima', 'Emeka', 'Ngozi', 'Adeyemi', 'Bola', 'Yemi', 'Uche', 'Aisha',
    'Segun', 'Ifeoma', 'Musa', 'Ada', 'Kunle', 'Zainab', 'Obi', 'Folake', 'Sani', 'Nneka',
];

$surnames = [
    'Bakare', 'Eze', 'Yusuf', 'Obi', 'Okafor', 'Bello', 'Adeyemi', 'Lawal', 'Nwosu', 'Danjuma',
    'Balogun', 'Chukwu', 'Ibrahim', 'Okonkwo', 'Adeleke', 'Mohammed', 'Uzoma', 'Ogundipe',
    'Abubakar', 'Nwachukwu',
];

// Two of these are spelt loosely ("ss3 c" and "SS1-B") so that a fifth of any
// generated file exercises StudentImport::normaliseClassKey rather than only
// the exact-match path.
$classes = ['JSS1A', 'JSS1B', 'JSS2A', 'JSS3C', 'SS1A', 'SS2B', 'SS3A', 'SS3B', 'ss3 c', 'SS1-B'];

// Every 25th row carries its own admission number; the rest are blank and get
// one generated during the import. The year is hardcoded rather than taken
// from date('Y') to keep the output stable, and the prefix is SCH rather than
// ADM so a supplied number is visibly distinct from a generated one.
const SUPPLIED_EVERY  = 25;
const SUPPLIED_PREFIX = 'SCH/2026/';

$rows = isset($argv[1]) ? (int) $argv[1] : 250;
if ($rows < 1) {
    fwrite(STDERR, "Row count must be at least 1.\n");
    exit(1);
}

$target = $argv[2] ?? 'php://stdout';
$handle = fopen($target, 'w');
if ($handle === false) {
    fwrite(STDERR, "Could not open {$target} for writing.\n");
    exit(1);
}

fputcsv($handle, ['name', 'class', 'admission_no']);

for ($i = 0; $i < $rows; $i++) {
    // The surname advances every 7 rows while the first name advances every
    // row, so names collide without the file degenerating into 20 repeats.
    $name = $firstNames[$i % count($firstNames)]
          . ' '
          . $surnames[intdiv($i, 7) % count($surnames)];

    $admissionNo = ($i % SUPPLIED_EVERY === 0)
        ? SUPPLIED_PREFIX . str_pad((string) $i, 3, '0', STR_PAD_LEFT)
        : '';

    fputcsv($handle, [$name, $classes[$i % count($classes)], $admissionNo]);
}

fclose($handle);

if ($target !== 'php://stdout') {
    fwrite(STDERR, sprintf("Wrote %d rows to %s\n", $rows, $target));
}
