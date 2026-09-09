<?php
/**
 * Prepare the test database for a users-list run: a fresh schema, one admin to
 * sign in as, and the 250-row fixture imported the way an admin would import
 * it, so the rows carry a real batch id and can be removed by it afterwards.
 */
require __DIR__ . '/bootstrap.php';

test_reset_database();

$db = Database::getInstance();
$db->prepare(
    "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')"
)->execute(['Test Admin', 'testadmin@exam.local', Password::hash('test-admin-pass-123')]);

$csv = sys_get_temp_dir() . '/users_fixture_250.csv';
passthru(sprintf(
    '%s %s 250 > %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(APP_ROOT . '/database/fixtures/generate_students.php'),
    escapeshellarg($csv)
));

$import = new StudentImport();
$parsed = $import->parse($csv);
if ($parsed['errors'] !== []) {
    fwrite(STDERR, "fixture did not parse cleanly\n");
    exit(1);
}

$rows    = $import->prepare($import->assignAdmissionNumbers($parsed['rows']));
$batchId = (new ImportBatch())->create('users_fixture_250.csv', count($rows), 1);
$result  = $import->commit($rows, $batchId);

if ($result['error'] !== null) {
    fwrite(STDERR, $result['error'] . "\n");
    exit(1);
}

file_put_contents(APP_ROOT . '/tests/.batch_id', $batchId);
unlink($csv);

printf("seeded: %d students in batch %s\n", $result['created'], $batchId);
printf("admin:  testadmin@exam.local / test-admin-pass-123\n");
