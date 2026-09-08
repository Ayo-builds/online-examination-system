<?php
class Auth
{
    // Normalise a submitted identifier into the exact string used for BOTH the
    // user lookup and the lockout key.
    //
    // This is the single most important function in the throttle: if the two
    // ever disagree, someone locked out under one spelling walks in under
    // another. Emails fold to lower case, admission numbers to upper case, and
    // every caller goes through here.
    public static function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        return self::looksLikeEmail($identifier)
            ? mb_strtolower($identifier)
            : mb_strtoupper($identifier);
    }

    // Shape, not validity: it only decides which column to search. A malformed
    // address still fails the lookup, it just fails it against users.email.
    public static function looksLikeEmail(string $identifier): bool
    {
        return str_contains($identifier, '@');
    }

    // Attempt login with an email (staff) or an admission number (students).
    // Returns true on success, false on failure.
    public static function attempt(string $identifier, string $password): bool
    {
        $identifier = self::normalizeIdentifier($identifier);
        $userModel  = new User();

        // Each identifier kind resolves against one role set only, so a person
        // has exactly one way to sign in and there is no ambiguity to exploit.
        $user = self::looksLikeEmail($identifier)
            ? $userModel->findStaffByEmail($identifier)
            : $userModel->findStudentByAdmissionNo($identifier);

        if ($user === null) {
            return false;
        }

        if ($user['status'] !== 'active') {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // SUCCESS, so regenerate the session ID before storing anything
        session_regenerate_id(true);

        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id'   => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'role' => $_SESSION['role'],
        ];
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        // Kill the session cookie in the browser too.
        // Uses the options-array signature (PHP 7.3+) rather than the positional
        // one, which has no samesite parameter, so the delete-cookie now carries
        // the same attributes as the cookie it is replacing. Browsers match on
        // name/path/domain when expiring, so this worked before; it just emitted
        // a cookie whose attributes disagreed with the original.
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }

        session_destroy();
    }

    // Is this identifier currently locked out? Returns seconds remaining, or 0
    // if clear.
    public static function lockoutRemaining(string $identifier): int
    {
        $row = Database::getInstance()->prepare(
            "SELECT locked_until FROM login_attempts WHERE identifier = ? LIMIT 1"
        );
        $row->execute([self::normalizeIdentifier($identifier)]);
        $result = $row->fetch();

        if ($result === false || $result['locked_until'] === null) {
            return 0;
        }

        $remaining = strtotime($result['locked_until']) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    // Record a failed attempt; lock the identifier if the threshold is crossed.
    public static function recordFailure(string $identifier): void
    {
        $identifier = self::normalizeIdentifier($identifier);
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT attempts FROM login_attempts WHERE identifier = ? LIMIT 1");
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        $attempts = ($row === false ? 0 : (int) $row['attempts']) + 1;

        $lockedUntil = null;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);
        }

        $db->prepare(
            "INSERT INTO login_attempts (identifier, attempts, locked_until, last_attempt)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                locked_until = VALUES(locked_until),
                last_attempt = NOW()"
        )->execute([$identifier, $attempts, $lockedUntil]);
    }

    // Clear the record on successful login.
    public static function clearFailures(string $identifier): void
    {
        Database::getInstance()
            ->prepare("DELETE FROM login_attempts WHERE identifier = ?")
            ->execute([self::normalizeIdentifier($identifier)]);
    }
}
