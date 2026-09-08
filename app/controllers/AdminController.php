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

            // Students never carry an email: users.email means "staff login
            // address" and nothing else. The form does not render the field for
            // this role, so anything arriving here came from a stale page or a
            // tampered POST. Discard it rather than erroring, because there is
            // no legitimate way for an admin to have typed it.
            $email = '';
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
            $isStudent ? null : $email,
            $password,
            $role,
            $isStudent ? $admissionNo : null,
            $isStudent ? $classId : null
        );

        $this->redirect('admin/users');
    }

    // ---- Bulk student import ------------------------------------------------
    //
    // Three steps, because creating a year group's worth of accounts is not
    // something to do on a single click:
    //
    //   importStudents  the upload form
    //   previewImport   parse and validate, show what WOULD be created
    //   confirmImport   write it, then hand off to the credential slips
    //
    // The parsed rows live in the session between preview and confirm, so the
    // admin confirms exactly the file they reviewed rather than a re-upload
    // that might differ.
    private const IMPORT_SESSION_KEY     = 'student_import';
    private const CREDENTIALS_SESSION_KEY = 'student_import_credentials';

    // GET /admin/importStudents
    public function importStudents(): void
    {
        $this->view('admin/import_students', [
            'classes' => (new SchoolClass())->selectable(),
        ]);
    }

    // POST /admin/previewImport. Reads the file, writes nothing.
    public function previewImport(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/importStudents');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->importError('Session expired. Please try again.');
            return;
        }

        $file = $_FILES['csv'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->importError($this->uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE));
            return;
        }

        // is_uploaded_file, not just a path check: it is the one test that a
        // path actually came from this request's upload rather than being
        // pointed at something else on disk.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->importError('That upload could not be verified. Please try again.');
            return;
        }

        if ($file['size'] > StudentImport::MAX_BYTES) {
            $this->importError('That file is larger than 1 MB. A student list should be far smaller.');
            return;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            $this->importError('Please upload a .csv file. Export it from Excel as "CSV (Comma delimited)".');
            return;
        }

        $import = new StudentImport();
        $result = $import->parse($file['tmp_name']);

        $rows = $import->assignAdmissionNumbers($result['rows']);

        // Only the fields the confirm step needs. Passwords are NOT generated
        // yet: nothing secret is put in the session until the admin commits.
        $_SESSION[self::IMPORT_SESSION_KEY] = [
            'rows'     => $rows,
            'filename' => $file['name'],
        ];

        $this->view('admin/import_preview', [
            'rows'     => $rows,
            'errors'   => $result['errors'],
            'filename' => $file['name'],
        ]);
    }

    // POST /admin/confirmImport. The only step that writes.
    public function confirmImport(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/importStudents');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->importError('Session expired. Please upload the file again.');
            return;
        }

        $pending = $_SESSION[self::IMPORT_SESSION_KEY] ?? null;
        if ($pending === null || empty($pending['rows'])) {
            $this->importError('There is nothing waiting to be imported. Please upload the file again.');
            return;
        }

        // Consume it immediately, so a double submit cannot import twice even
        // if the second request arrives before the first has finished writing.
        unset($_SESSION[self::IMPORT_SESSION_KEY]);

        $import = new StudentImport();

        // Hashing happens here, outside the transaction. This is the slow part.
        $rows   = $import->prepare($pending['rows']);
        $result = $import->commit($rows);

        if ($result['error'] !== null) {
            $this->importError($result['error']);
            return;
        }

        // Hand the plaintext to the slips page and redirect, so a refresh of
        // the result cannot re-post the import. The slips page clears this the
        // moment it has rendered.
        $_SESSION[self::CREDENTIALS_SESSION_KEY] = array_map(static function ($r) {
            return [
                'full_name'    => $r['full_name'],
                'admission_no' => $r['admission_no'],
                'class_label'  => $r['class_label'],
                'password'     => $r['password'],
            ];
        }, $rows);

        $this->redirect('admin/credentialSlips');
    }

    // GET /admin/credentialSlips. Renders once, then forgets.
    public function credentialSlips(): void
    {
        $credentials = $_SESSION[self::CREDENTIALS_SESSION_KEY] ?? null;

        // Cleared before rendering, not after: if the view throws half way
        // through, the plaintext still does not survive into the next request.
        unset($_SESSION[self::CREDENTIALS_SESSION_KEY]);

        $this->view('admin/credential_slips', [
            'credentials' => $credentials ?? [],
        ]);
    }

    // Re-render the upload form carrying an error.
    private function importError(string $message): void
    {
        $this->view('admin/import_students', [
            'error'   => $message,
            'classes' => (new SchoolClass())->selectable(),
        ]);
    }

    private function uploadErrorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_NO_FILE:
                return 'Please choose a CSV file to upload.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'That file is too large to upload.';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was interrupted. Please try again.';
            default:
                return 'The upload failed. Please try again.';
        }
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