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
