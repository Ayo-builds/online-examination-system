<?php
class AuthController extends Controller
{
    // login_attempts.identifier is VARCHAR(150) and the table's primary key, so
    // anything longer cannot be throttled. Rejecting it up front keeps the
    // lockout honest instead of letting a long string error out or truncate
    // into a shared bucket.
    private const MAX_IDENTIFIER_LENGTH = 150;

    // GET /auth/login. Show the form
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect($this->homeFor(Auth::role()));
        }
        $this->view('auth/login');
    }

    // POST /auth/authenticate. Process the form
    //
    // One form serves both audiences: students type an admission number, staff
    // type an email, and Auth decides which by shape. Every failure below says
    // the same thing, so the page never reveals which identifiers exist.
    public function authenticate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('auth/login');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->view('auth/login', ['error' => 'Session expired. Please try again.']);
            return;
        }

        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        if ($identifier === '' || $password === '') {
            $this->view('auth/login', [
                'error' => 'Please fill in both fields.',
                'old'   => ['identifier' => $identifier],
            ]);
            return;
        }

        if (mb_strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            $this->view('auth/login', ['error' => 'Invalid credentials.']);
            return;
        }

        // Locked out? Auth normalises the identifier the same way for the
        // lockout key and the user lookup, so a student cannot dodge a lock by
        // changing the case of their admission number.
        $lockRemaining = Auth::lockoutRemaining($identifier);
        if ($lockRemaining > 0) {
            $mins = ceil($lockRemaining / 60);
            $this->view('auth/login', [
                'error' => "Too many failed attempts. Try again in {$mins} minute(s).",
                'old'   => ['identifier' => $identifier],
            ]);
            return;
        }

        if (!Auth::attempt($identifier, $password)) {
            Auth::recordFailure($identifier);
            $this->view('auth/login', [
                'error' => 'Invalid credentials.',
                'old'   => ['identifier' => $identifier],
            ]);
            return;
        }

        Auth::clearFailures($identifier);
        $this->redirect($this->homeFor(Auth::role()));
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('auth/login');
    }

    // Each role lands on its own dashboard
    private function homeFor(?string $role): string
    {
        return match ($role) {
            'admin'    => 'admin/dashboard',
            'lecturer' => 'lecturer/dashboard',
            'student'  => 'student/dashboard',
            default    => 'auth/login',
        };
    }
}
