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

    public function courses(): void
    {
        $courses = (new Course())->allWithLecturer();
        $this->view('admin/courses', ['courses' => $courses]);
    }

    public function createCourse(): void
    {
        $lecturers = (new User())->activeByRole('lecturer');
        $this->view('admin/create_course', ['lecturers' => $lecturers]);
    }

    public function storeCourse(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/createCourse');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/createCourse');
        }

        $code       = strtoupper(trim($_POST['course_code'] ?? ''));
        $title      = trim($_POST['title'] ?? '');
        $lecturerId = (int) ($_POST['lecturer_id'] ?? 0);

        $errors = [];

        if (!preg_match('/^[A-Z]{2,5}[0-9]{3}$/', $code)) {
            $errors[] = 'Course code must be 2-5 letters followed by 3 digits (e.g. CSC301).';
        }
        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Title is required (max 150 characters).';
        }

        // The lecturer must exist, be a lecturer, and be active
        $lecturer = (new User())->find($lecturerId);
        if ($lecturer === null || $lecturer['role'] !== 'lecturer' || $lecturer['status'] !== 'active') {
            $errors[] = 'Please choose a valid lecturer.';
        }

        $courseModel = new Course();

        if (empty($errors) && $courseModel->findByCode($code) !== null) {
            $errors[] = 'That course code already exists.';
        }

        if (!empty($errors)) {
            $lecturers = (new User())->activeByRole('lecturer');
            $this->view('admin/create_course', [
                'errors'    => $errors,
                'lecturers' => $lecturers,
                'old'       => ['course_code' => $code, 'title' => $title, 'lecturer_id' => $lecturerId],
            ]);
            return;
        }

        $courseModel->create($code, $title, $lecturerId);

        $this->redirect('admin/courses');
    }
}