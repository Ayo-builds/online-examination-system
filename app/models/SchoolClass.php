<?php
class SchoolClass extends Model
{
    protected string $table = 'classes';

    // 'UNASSIGNED' is the placeholder the migration parks pre-existing students
    // in. It is a real row so that class_id is a clean foreign key, but it is
    // never offered as a choice when creating someone new.
    public const PLACEHOLDER = 'UNASSIGNED';

    // Real classes only, in year order (the ENUM sorts by declaration, so the
    // placeholder would otherwise trail every list harmlessly, but an admin
    // must not be able to file a new student under it).
    public function selectable(): array
    {
        return $this->query(
            "SELECT id, year_group, arm
               FROM classes
              WHERE year_group <> ?
           ORDER BY year_group, arm",
            [self::PLACEHOLDER]
        )->fetchAll();
    }

    // Real classes with how many active students each holds, for the
    // whole-class reset picker. A class with nobody in it is still listed, so
    // an admin can see it exists rather than wondering where it went.
    public function selectableWithCounts(): array
    {
        return $this->query(
            "SELECT c.id, c.year_group, c.arm,
                    (SELECT COUNT(*) FROM users u
                      WHERE u.class_id = c.id
                        AND u.role = 'student'
                        AND u.status = 'active') AS student_count
               FROM classes c
              WHERE c.year_group <> ?
           ORDER BY c.year_group, c.arm",
            [self::PLACEHOLDER]
        )->fetchAll();
    }

    // "SS3A", or "SS3" for a school with a single stream.
    public static function label(?string $yearGroup, ?string $arm): string
    {
        if ($yearGroup === null || $yearGroup === '') {
            return '';
        }
        if ($yearGroup === self::PLACEHOLDER) {
            return 'Unassigned';
        }

        return $yearGroup . (string) $arm;
    }

    // Label for a users row that was fetched with the classes join.
    public static function labelFor(array $row): string
    {
        return self::label($row['year_group'] ?? null, $row['arm'] ?? null);
    }
}
