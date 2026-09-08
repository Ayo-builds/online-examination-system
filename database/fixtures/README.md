# Import fixtures

Test files for the bulk student import (`admin/importStudents`) and the batch
delete (`admin/importBatches`). They exist so those paths can be re-checked
after a change without hand-building a CSV and guessing what should happen.

Nothing here is real data. Every name is fabricated.

These files are served to nobody: `database/.htaccess` denies HTTP access to
this directory and everything under it. Verified — a request for
`/exam-system/database/fixtures/students_messy.csv` returns 403.

---

## `students_messy.csv` — validation coverage

Twelve data rows, one per failure mode, plus the two that must succeed.
**Expect 3 accepted and 8 rejected.** The blank row is skipped silently and is
not counted as an error.

| Line | Row | Expected |
|-----:|-----|----------|
| 2 | `Valid Student, SS3A` | accepted |
| 3 | name empty | `name is missing` |
| 4 | class empty | `class is missing` |
| 5 | class `SS9Z` | `class "SS9Z" is not a class in this school` |
| 6 | class `UNASSIGNED` | rejected — the placeholder class is never importable |
| 7 | 101-character name | `name is longer than 100 characters` |
| 8 | admission `has space` | `is not a valid format` |
| 9 | admission `ADM/2026/0004` | `already belongs to someone` |
| 10 | admission `SCH/9/111` | accepted |
| 11 | admission `SCH/9/111` again | `appears twice in this file (already on line 10)` |
| 12 | wholly blank | skipped, no error |
| 13 | class `ss3 c` | accepted as `SS3C` — loose spelling resolves |

Line 9 depends on `ADM/2026/0004` existing, which it does in any database that
has run migration 001 against the seeded students. On a database without it,
that row is accepted instead and the totals become 4 and 7.

Line 6 is the one worth keeping an eye on. `UNASSIGNED` is a real row in
`classes` — it is where migration 001 parked the pre-existing students — so it
would resolve if the importer looked classes up naively. It must not.

---

## `batch12.csv` — the batch-delete path

Twelve clean rows, no admission numbers, cycling `SS3A` / `SS3B` / `JSS1A`.
Small enough to verify by hand.

To exercise the refusal, give some of the imported accounts an exam attempt
before deleting the batch. Rows 1, 6 and 12 — **Ada Okoro**, **Femi Dada**,
**Lami Bature** — are the three used when this was built, chosen to span the
three attempt states:

```sql
INSERT INTO exam_attempts (exam_id, student_id, started_at, deadline_at, submitted_at, status, grading_status)
SELECT 1, id, NOW(), NOW() + INTERVAL 1 HOUR, NULL, 'in_progress', 'pending'
  FROM users WHERE full_name = 'Ada Okoro';
```

Expected outcome of deleting that batch: **9 deleted, 3 kept and named**, their
9 course enrolments cascaded with no orphans, all attempts intact, and the
batch record still listed. Deleting a second time is a no-op that reports the
same 3.

---

## `generate_students.php` — the load-test file

```
php database/fixtures/generate_students.php            > students_250.csv
php database/fixtures/generate_students.php 500        > students_500.csv
php database/fixtures/generate_students.php 250 out.csv
```

Deterministic: no randomness, and no reference to the current date, so a given
row count always produces a byte-identical file. The 250-row output has SHA-1
`1cb6a6ff69429758dc2a5575c086fa37eb51282b` and is the exact file the import was
timed against.

The output is **not committed** — it is a second's work to regenerate, and a
CSV of fabricated names does not belong in git.

What a generated file contains, at 250 rows:

- 25 rows in each of 10 classes, two of which (`ss3 c`, `SS1-B`) are spelt
  loosely, so a fifth of the file exercises class-key normalisation.
- 10 rows carrying their own admission number (`SCH/2026/000`, `/025`, `/050` …);
  the other 240 are blank and get one generated.
- 140 distinct names across 250 rows. Names repeat deliberately: two students
  sharing a name across different arms is the normal case in a Nigerian
  secondary school, and it is the reason grading surfaces show an admission
  number rather than a name alone.

### Reference timings

Measured through Apache on a development machine, PHP 8.0, MariaDB 10.4,
bcrypt cost 10. Roughly 110 ms per hash is the whole cost; everything else is
noise.

| Step | 250 rows |
|------|---------:|
| Upload, parse and validate (no writes) | 0.06 s |
| Confirm — hash, insert, redirect | 24.6 s |
| Delete the batch | 0.03 s |

If the confirm step ever approaches the host's `max_execution_time`, that is
the signal to lower the batch size rather than the bcrypt cost.
`StudentImport::prepare()` resets the time limit per row precisely so a slower
machine degrades into taking longer rather than into a half-finished import.
