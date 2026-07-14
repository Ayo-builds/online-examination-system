<?php
class RoleGuard
{
    // Allow only the given roles. Anyone else is stopped here.
    public static function require(array $allowedRoles): void
    {
        // Gate 1: must be logged in at all
        if (!Auth::check()) {
            header('Location: ' . BASE_URL . 'auth/login');
            exit;
        }

        // Gate 2: must hold one of the allowed roles
        if (!in_array(Auth::role(), $allowedRoles, true)) {
            http_response_code(403);
            exit('403 — You do not have permission to access this page.');
        }
    }
}