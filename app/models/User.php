<?php
class User extends Model
{
    protected string $table = 'users';

    // Staff sign in with an email, so an email only ever resolves to staff.
    // Scoping the lookup by role here is what stops a student who happens to
    // have an email on file from having a second way in.
    public function findStaffByEmail(string $email): ?array
    {
        $row = $this->query(
            "SELECT * FROM users WHERE email = ? AND role IN ('admin','lecturer') LIMIT 1",
            [$email]
        )->fetch();

        return $row ?: null;
    }

    // The mirror of the above: an admission number only ever resolves to a
    // student.
    public function findStudentByAdmissionNo(string $admissionNo): ?array
    {
        $row = $this->query(
            "SELECT * FROM users WHERE admission_no = ? AND role = 'student' LIMIT 1",
            [$admissionNo]
        )->fetch();

        return $row ?: null;
    }

    // Uniqueness checks for the create-user form. These are deliberately NOT
    // role-scoped: the columns are unique across the whole table, so the
    // form has to refuse a collision with any row, staff or student.
    public function findByEmail(string $email): ?array
    {
        $row = $this->query(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$email]
        )->fetch();

        return $row ?: null;
    }

    public function findByAdmissionNo(string $admissionNo): ?array
    {
        $row = $this->query(
            "SELECT * FROM users WHERE admission_no = ? LIMIT 1",
            [$admissionNo]
        )->fetch();

        return $row ?: null;
    }

    // All users, newest first, for the admin list. LEFT JOIN because staff have
    // no class and a student may sit outside one.
    public function allByNewest(): array
    {
        return $this->query(
            "SELECT u.id, u.full_name, u.email, u.admission_no, u.role, u.status, u.created_at,
                    c.year_group, c.arm
               FROM users u
          LEFT JOIN classes c ON c.id = u.class_id
           ORDER BY u.created_at DESC"
        )->fetchAll();
    }

    // $email is null for a student who was not given one; $admissionNo and
    // $classId are null for staff. The schema's CHECK constraint holds the
    // same line if a caller ever gets this wrong.
    public function create(
        string $fullName,
        ?string $email,
        string $password,
        string $role,
        ?string $admissionNo = null,
        ?int $classId = null
    ): int {
        $this->query(
            "INSERT INTO users (full_name, email, password_hash, role, admission_no, class_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $fullName,
                $email !== '' ? $email : null,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $admissionNo,
                $classId,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // ---- Admin users list ---------------------------------------------------
    //
    // Both methods below take the SAME UserListQuery and call where() on it, so
    // the count and the page are filtered identically by construction. There is
    // no second copy of the criteria to fall out of step.
    //
    // The join is LEFT because a member of staff has no class and a student may
    // not be placed in one yet; an INNER join would silently drop them from the
    // list entirely.
    private const LIST_FROM = "  FROM users u
                            LEFT JOIN classes c ON c.id = u.class_id";

    public function countForList(UserListQuery $query): int
    {
        [$where, $bindings] = $query->where();

        return (int) $this->query(
            "SELECT COUNT(*)" . self::LIST_FROM . $where,
            $bindings
        )->fetchColumn();
    }

    public function forList(UserListQuery $query): array
    {
        [$where, $bindings] = $query->where();

        // LIMIT and OFFSET are cast to int in PHP and interpolated, not bound.
        // Binding them is possible but MySQL will not accept a string there
        // under emulated prepares off, and an int cast is the whole of the
        // validation these two need. Both come from UserListQuery, where page
        // is floored at 1 and per_page is an allowlist member.
        $limit  = (int) $query->perPage;
        $offset = (int) $query->offset();

        return $this->query(
            "SELECT u.id, u.full_name, u.email, u.admission_no, u.role, u.status,
                    u.created_at, c.year_group, c.arm"
            . self::LIST_FROM
            . $where
            . $query->orderBy()
            . " LIMIT {$limit} OFFSET {$offset}",
            $bindings
        )->fetchAll();
    }

    // Does the table hold any user at all? Distinguishes "nothing matches these
    // filters" from "nothing here yet", which need different empty states.
    public function anyExist(): bool
    {
        return (int) $this->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
    }

    // One user with their class joined on, for a slip. Model::find() is a
    // plain SELECT * and carries no year_group or arm, so a slip built from
    // it would silently lose the class.
    public function findWithClass(int $id): ?array
    {
        $row = $this->query(
            "SELECT u.*, c.year_group, c.arm
               FROM users u
          LEFT JOIN classes c ON c.id = u.class_id
              WHERE u.id = ? LIMIT 1",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    // Replace someone's password. Takes a hash, never a plaintext: the caller
    // generates and hashes so it still holds the plaintext to put on a slip,
    // and this method has no way to write one by accident.
    public function setPasswordHash(int $id, string $hash): void
    {
        $this->query(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$hash, $id]
        );
    }

    // Active students in one class, for a whole-class password reset.
    // Suspended accounts are left out: reissuing a credential for an account
    // that cannot sign in wastes a slip and confuses whoever hands it over.
    public function activeStudentsInClass(int $classId): array
    {
        return $this->query(
            "SELECT u.id, u.full_name, u.admission_no, c.year_group, c.arm
               FROM users u
          LEFT JOIN classes c ON c.id = u.class_id
              WHERE u.role = 'student'
                AND u.status = 'active'
                AND u.class_id = ?
           ORDER BY u.full_name",
            [$classId]
        )->fetchAll();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->query(
            "UPDATE users SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }

    public function activeByRole(string $role): array
    {
        return $this->query(
            "SELECT id, full_name FROM users
             WHERE role = ? AND status = 'active'
             ORDER BY full_name",
            [$role]
        )->fetchAll();
    }
}
