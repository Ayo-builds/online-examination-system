<?php
class Auth
{
    // Attempt login. Returns true on success, false on failure.
    public static function attempt(string $email, string $password): bool
    {
        $user = (new User())->findByEmail($email);

        if ($user === null) {
            return false;
        }

        if ($user['status'] !== 'active') {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // SUCCESS — regenerate the session ID before storing anything
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

        // Kill the session cookie in the browser too
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }
}