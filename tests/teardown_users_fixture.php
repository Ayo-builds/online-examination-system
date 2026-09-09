<?php
/**
 * Remove what setup_users_fixture.php created, by import batch id and nothing
 * else. No DELETE by role, by name pattern or by date: the batch id is the
 * only thing that identifies these rows as ours, so it is the only thing
 * allowed to select them for deletion.
 */
require __DIR__ . '/bootstrap.php';

$marker = APP_ROOT . '/tests/.batch_id';

if (!is_file($marker)) {
    fwrite(STDERR, "No tests/.batch_id - nothing recorded to clean up.\n");
    exit(1);
}

$batchId = trim((string) file_get_contents($marker));
$batches = new ImportBatch();

if ($batches->findBatch($batchId) === null) {
    fwrite(STDERR, "Batch $batchId is not in this database. Nothing done.\n");
    exit(1);
}

$before  = count($batches->members($batchId));
$outcome = $batches->deleteMembers($batchId);

if ($outcome['error'] !== null) {
    fwrite(STDERR, $outcome['error'] . "\n");
    exit(1);
}

printf("batch %s: %d members, %d deleted, %d kept (have exam attempts)\n",
    $batchId, $before, $outcome['deleted'], count($outcome['skipped']));

$db = Database::getInstance();
printf("remaining users: %d\n", (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn());

unlink($marker);
