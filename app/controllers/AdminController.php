<?php
class AdminController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['admin']);
    }

    public function dashboard(): void
    {
        $this->view('admin/dashboard', ['user' => Auth::user()]);
    }

    public function users(): void
    {
        $users = (new User())->allByNewest();
        $this->view('admin/users', ['users' => $users]);
    }

    // GET /admin/createUser — show the form
    public function createUser(): void
    {
        $this->view('admin/create_user');
    }

    // POST /admin/storeUser — process it
    public function storeUser(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/createUser');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->view('admin/create_user', ['error' => 'Session expired. Please try again.']);
            return;
        }

        // ---- Gather + trim ----
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '';

        // ---- Validate, collecting ALL problems ----
        $errors = [];

        if ($fullName === '' || mb_strlen($fullName) > 100) {
            $errors[] = 'Full name is required (max 100 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!in_array($role, ['admin', 'lecturer', 'student'], true)) {
            $errors[] = 'Role must be admin, lecturer, or student.';
        }

        $userModel = new User();

        if (empty($errors) && $userModel->findByEmail($email) !== null) {
            $errors[] = 'That email is already registered.';
        }

        if (!empty($errors)) {
            $this->view('admin/create_user', [
                'errors' => $errors,
                'old'    => ['full_name' => $fullName, 'email' => $email, 'role' => $role],
            ]);
            return;
        }

        $userModel->create($fullName, $email, $password, $role);

        $this->redirect('admin/users');
    }

    // POST /admin/toggleStatus/{id}
    public function toggleStatus(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/users');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/users');
        }

        $id = (int) $id;

        $userModel = new User();
        $user = $userModel->find($id);

        if ($user === null) {
            $this->redirect('admin/users');
        }

        // An admin cannot suspend themselves
        if ($id === (int) Auth::user()['id']) {
            $this->redirect('admin/users');
        }

        $newStatus = $user['status'] === 'active' ? 'suspended' : 'active';
        $userModel->setStatus($id, $newStatus);

        $this->redirect('admin/users');
    }
}