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

    // GET /admin/createUser. Show the form
    public function createUser(): void
    {
        $this->view('admin/create_user', ['classes' => (new SchoolClass())->selectable()]);
    }

    // POST /admin/storeUser. Process it
    //
    // The two roles carry different identities: a student is identified by an
    // admission number and sits in a class, staff by an email address. The
    // validation below branches on role rather than demanding both of everyone.
    public function storeUser(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/createUser');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->view('admin/create_user', [
                'error'   => 'Session expired. Please try again.',
                'classes' => (new SchoolClass())->selectable(),
            ]);
            return;
        }

        // ---- Gather + trim ----
        $fullName    = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $password    = $_POST['password'] ?? '';
        $role        = $_POST['role'] ?? '';
        // Upper-cased on the way in so it is stored exactly as Auth will
        // normalise it at sign-in. Storing 'adm/2026/0004' and looking up
        // 'ADM/2026/0004' happens to work under the table's case-insensitive
        // collation, but the value an admin reads off the screen should be the
        // value the student types.
        $admissionNo = mb_strtoupper(trim($_POST['admission_no'] ?? ''));
        $classId     = (int) ($_POST['class_id'] ?? 0);

        $isStudent = $role === 'student';

        // ---- Validate, collecting ALL problems ----
        $errors    = [];
        $userModel = new User();

        if ($fullName === '' || mb_strlen($fullName) > 100) {
            $errors[] = 'Full name is required (max 100 characters).';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!in_array($role, ['admin', 'lecturer', 'student'], true)) {
            $errors[] = 'Role must be admin, lecturer, or student.';
        }

        if ($isStudent) {
            // Admission number: required, and the student's only way in.
            if ($admissionNo === '') {
                $errors[] = 'Admission number is required for a student.';
            } elseif (!preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,29}$/', $admissionNo)) {
                $errors[] = 'Admission number must be 3-30 characters: letters, digits, / or - '
                          . '(e.g. ADM/2026/0004).';
            } elseif ($userModel->findByAdmissionNo($admissionNo) !== null) {
                $errors[] = 'That admission number is already in use.';
            }

            // Class: nullable in the schema so the migration's backfilled rows
            // stay valid, but required here. Nothing should be created without
            // one going forward.
            $class = $classId > 0 ? (new SchoolClass())->find($classId) : null;
            if ($class === null || $class['year_group'] === SchoolClass::PLACEHOLDER) {
                $errors[] = 'Please choose a class.';
            }

            // Email is optional for a student. Validate it only if given.
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'That email address is not valid. Leave it blank if the student has none.';
                } elseif ($userModel->findByEmail($email) !== null) {
                    $errors[] = 'That email is already registered.';
                }
            }
        } else {
            // Staff still sign in with an email, so it stays required.
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required.';
            } elseif ($userModel->findByEmail($email) !== null) {
                $errors[] = 'That email is already registered.';
            }
        }

        if (!empty($errors)) {
            $this->view('admin/create_user', [
                'errors'  => $errors,
                'classes' => (new SchoolClass())->selectable(),
                'old'     => [
                    'full_name'    => $fullName,
                    'email'        => $email,
                    'role'         => $role,
                    'admission_no' => $admissionNo,
                    'class_id'     => $classId,
                ],
            ]);
            return;
        }

        $userModel->create(
            $fullName,
            $email !== '' ? $email : null,
            $password,
            $role,
            $isStudent ? $admissionNo : null,
            $isStudent ? $classId : null
        );

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

    // GET /admin/enrollments/{courseId}
    public function enrollments(string $courseId = ''): void
    {
        $courseId = (int) $courseId;

        $course = (new Course())->find($courseId);
        if ($course === null) {
            $this->redirect('admin/courses');
        }

        $enrollmentModel = new Enrollment();

        $this->view('admin/enrollments', [
            'course'    => $course,
            'enrolled'  => $enrollmentModel->studentsInCourse($courseId),
            'available' => $enrollmentModel->studentsNotInCourse($courseId),
        ]);
    }

    // POST /admin/enroll/{courseId}
    public function enroll(string $courseId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/courses');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/courses');
        }

        $courseId  = (int) $courseId;
        $studentId = (int) ($_POST['student_id'] ?? 0);

        $course  = (new Course())->find($courseId);
        $student = (new User())->find($studentId);

        if ($course === null
            || $student === null
            || $student['role'] !== 'student'
            || $student['status'] !== 'active') {
            $this->redirect('admin/courses');
        }

        $enrollmentModel = new Enrollment();

        if (!$enrollmentModel->isEnrolled($studentId, $courseId)) {
            $enrollmentModel->enroll($studentId, $courseId);
        }

        $this->redirect('admin/enrollments/' . $courseId);
    }

    // POST /admin/unenroll/{courseId}
    public function unenroll(string $courseId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/courses');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/courses');
        }

        $courseId  = (int) $courseId;
        $studentId = (int) ($_POST['student_id'] ?? 0);

        (new Enrollment())->unenroll($studentId, $courseId);

        $this->redirect('admin/enrollments/' . $courseId);
    }
    public function analytics(): void
    {
        $analytics = new Analytics();

        $this->view('admin/analytics', [
            'counts'    => $analytics->systemCounts(),
            'integrity' => $analytics->integritySignals(),
            'events'    => $analytics->eventBreakdown(),
            'courses'   => $analytics->courseSummary(),
        ]);
    }
}