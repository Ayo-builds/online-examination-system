<?php
class AuthController extends Controller
{
    // GET /auth/login — show the form
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect($this->homeFor(Auth::role()));
        }
        $this->view('auth/login');
    }

    // POST /auth/authenticate — process the form
    public function authenticate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('auth/login');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->view('auth/login', ['error' => 'Session expired. Please try again.']);
            return;
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $this->view('auth/login', ['error' => 'Please fill in both fields.']);
            return;
        }

        if (!Auth::attempt($email, $password)) {
            $this->view('auth/login', ['error' => 'Invalid credentials.']);
            return;
        }

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