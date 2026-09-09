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

    // GET /admin/users
    //
    // Search, filter, sort and pagination all resolve in UserListQuery, which
    // validates every request value and hands back the WHERE fragment. The
    // count and the page are built from that same fragment, so the page numbers
    // always describe the rows on screen.
    public function users(): void
    {
        $userModel = new User();
        $query     = new UserListQuery($_GET);

        // Count first: the total is what tells us whether the requested page
        // still exists, and clamping before fetching avoids serving an empty
        // table for a bookmark to a page that has since fallen off the end.
        $total = $userModel->countForList($query);
        $query->clampToTotal($total);

        $users = $userModel->forList($query);

        $this->view('admin/users', [
            'users'   => $users,
            'query'   => $query,
            'total'   => $total,
            'classes' => (new SchoolClass())->selectable(),
            // Only asked when the page came back empty, to tell "no matches"
            // apart from "no users at all". No point paying for it otherwise.
            'anyUsers' => $users !== [] ? true : $userModel->anyExist(),
        ]);
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
    private const BATCH_RESULT_SESSION_KEY = 'import_batch_result';

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
        $rows = $import->prepare($pending['rows']);

        // The batch is recorded before the accounts, so every created row can
        // carry its id. If the insert then fails, an empty batch is left
        // behind: harmless, and it reads as the honest record of an import
        // that was attempted and wrote nothing.
        $batchId = (new ImportBatch())->create(
            $pending['filename'],
            count($rows),
            (int) Auth::user()['id']
        );

        $result = $import->commit($rows, $batchId);

        if ($result['error'] !== null) {
            $this->importError($result['error']);
            return;
        }

        // Hand the plaintext to the slips page and redirect, so a refresh of
        // the result cannot re-post the import. The slips page clears this the
        // moment it has rendered.
        $_SESSION[self::CREDENTIALS_SESSION_KEY] = [
            'context' => 'import',
            'label'   => $pending['filename'],
            'rows'    => array_map(static function ($r) {
                return [
                    'full_name'    => $r['full_name'],
                    'admission_no' => $r['admission_no'],
                    'email'        => '',
                    'class_label'  => $r['class_label'],
                    'password'     => $r['password'],
                ];
            }, $rows),
        ];

        $this->redirect('admin/credentialSlips');
    }

    // GET /admin/credentialSlips. Renders once, then forgets.
    public function credentialSlips(): void
    {
        $payload = $_SESSION[self::CREDENTIALS_SESSION_KEY] ?? null;

        // Cleared before rendering, not after: if the view throws half way
        // through, the plaintext still does not survive into the next request.
        unset($_SESSION[self::CREDENTIALS_SESSION_KEY]);

        $this->view('admin/credential_slips', [
            'credentials' => $payload['rows'] ?? [],
            'context'     => $payload['context'] ?? 'import',
            // Not 'label': the topbar partial runs a nav loop in the view's
            // scope, and a plain $label there gets overwritten by the last nav
            // link's text.
            'slip_label'  => $payload['label'] ?? '',
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

    // GET /admin/importBatches. What each import created, and what is left.
    public function importBatches(): void
    {
        // A one-shot result from the last delete, if there was one.
        $result = $_SESSION[self::BATCH_RESULT_SESSION_KEY] ?? null;
        unset($_SESSION[self::BATCH_RESULT_SESSION_KEY]);

        $this->view('admin/import_batches', [
            'batches' => (new ImportBatch())->allWithCounts(),
            'result'  => $result,
        ]);
    }

    // POST /admin/deleteImportBatch/{batchId}
    //
    // Undoes an import. Accounts that have already sat an exam are refused and
    // reported by name: deleting one would take a submitted script with it, and
    // an admin correcting a typo in a class list has not asked for that.
    public function deleteImportBatch(string $batchId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/importBatches');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/importBatches');
        }

        $batchModel = new ImportBatch();
        $batch      = $batchModel->findBatch($batchId);

        if ($batch === null) {
            $this->redirect('admin/importBatches');
        }

        // A batch of 250 accounts is 250 DELETEs plus their cascades. Same
        // reasoning as the import itself: the budget is refreshed rather than
        // assumed, so the host's max_execution_time cannot cut this in half and
        // leave the batch partly removed.
        set_time_limit(120);

        $outcome = $batchModel->deleteMembers($batchId);

        $_SESSION[self::BATCH_RESULT_SESSION_KEY] = [
            'filename' => $batch['filename'],
            'deleted'  => $outcome['deleted'],
            'skipped'  => $outcome['skipped'],
            'error'    => $outcome['error'],
        ];

        $this->redirect('admin/importBatches');
    }

    // ---- Password resets -----------------------------------------------------
    //
    // There is no self-service password change anywhere in this app, so a
    // forgotten password is an admin action. Both paths below issue a new
    // random credential, store only its hash, and hand the plaintext to the
    // same slip page the bulk import uses.

    // POST /admin/resetPassword/{id}. One person.
    public function resetPassword(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/users');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/users');
        }

        $userModel = new User();
        $user      = $userModel->findWithClass((int) $id);

        if ($user === null) {
            $this->redirect('admin/users');
        }

        $plaintext = Password::generate();
        $userModel->setPasswordHash((int) $user['id'], Password::hash($plaintext));

        $_SESSION[self::CREDENTIALS_SESSION_KEY] = [
            'context' => 'reset',
            'label'   => '',
            'rows'    => [$this->slipRowFor($user, $plaintext)],
        ];

        $this->redirect('admin/credentialSlips');
    }

    // GET /admin/resetClass. Pick a class.
    public function resetClass(): void
    {
        $this->view('admin/reset_class', [
            'classes' => (new SchoolClass())->selectableWithCounts(),
        ]);
    }

    // POST /admin/resetClassPasswords. A whole class at once.
    public function resetClassPasswords(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/resetClass');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/resetClass');
        }

        $classId    = (int) ($_POST['class_id'] ?? 0);
        $classModel = new SchoolClass();
        $class      = $classId > 0 ? $classModel->find($classId) : null;

        if ($class === null || $class['year_group'] === SchoolClass::PLACEHOLDER) {
            $this->view('admin/reset_class', [
                'error'   => 'Please choose a class.',
                'classes' => $classModel->selectableWithCounts(),
            ]);
            return;
        }

        $userModel = new User();
        $students  = $userModel->activeStudentsInClass($classId);

        if ($students === []) {
            $this->view('admin/reset_class', [
                'error'   => 'There are no active students in ' . SchoolClass::labelFor($class) . '.',
                'classes' => $classModel->selectableWithCounts(),
            ]);
            return;
        }

        // Same shape as the import: hash everything first, write afterwards.
        // A class is smaller than a 250-row import, but bcrypt costs the same
        // per row and the time limit is refreshed for the same reason.
        $issued = [];
        foreach ($students as $s) {
            set_time_limit(30);
            $plaintext = Password::generate();
            $issued[]  = [
                'user'      => $s,
                'plaintext' => $plaintext,
                'hash'      => Password::hash($plaintext),
            ];
        }

        // All or nothing. A class half-reset, with no record of which half, is
        // worse than a failed reset the admin can simply repeat.
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            foreach ($issued as $i) {
                $userModel->setPasswordHash((int) $i['user']['id'], $i['hash']);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('Class password reset failed: ' . $e->getMessage());
            $this->view('admin/reset_class', [
                'error'   => 'The reset failed and no password was changed. Please try again.',
                'classes' => $classModel->selectableWithCounts(),
            ]);
            return;
        }

        $rows = [];
        foreach ($issued as $i) {
            $rows[] = $this->slipRowFor($i['user'], $i['plaintext']);
        }

        $_SESSION[self::CREDENTIALS_SESSION_KEY] = [
            'context' => 'reset_class',
            'label'   => SchoolClass::labelFor($class),
            'rows'    => $rows,
        ];

        $this->redirect('admin/credentialSlips');
    }

    // One slip's worth of data. Staff carry an email and no admission number,
    // students the reverse, and the slip partial renders whichever is present.
    private function slipRowFor(array $user, string $plaintext): array
    {
        return [
            'full_name'    => $user['full_name'],
            'admission_no' => $user['admission_no'] ?? '',
            'email'        => $user['email'] ?? '',
            'class_label'  => SchoolClass::labelFor($user),
            'password'     => $plaintext,
        ];
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

    // The pending bulk enrolment, between the confirmation screen and the
    // write. Held in the session rather than in hidden form fields for the same
    // reason the import preview is: the numbers an admin was shown are what the
    // result is judged against, and a number the browser could edit would make
    // that comparison worthless.
    private const BULK_ENROLL_SESSION_KEY = 'bulk_enrollment_pending';

    // The outcome of the last bulk enrolment, shown once on the roll it changed.
    private const BULK_ENROLL_RESULT_SESSION_KEY = 'bulk_enrollment_result';

    // GET /admin/enrollments/{courseId}
    //
    // Search, filter, sort and pagination all resolve in EnrollmentListQuery,
    // which validates every request value and hands back the WHERE fragment.
    // The count and the page are built from that same fragment, so the page
    // numbers always describe the rows on screen. Same arrangement as the users
    // list; the course id is the one thing that comes from the path rather than
    // the query string.
    public function enrollments(string $courseId = ''): void
    {
        $courseId = (int) $courseId;

        $course = (new Course())->find($courseId);
        if ($course === null) {
            $this->redirect('admin/courses');
        }

        $enrollmentModel = new Enrollment();
        $query           = new EnrollmentListQuery($courseId, $_GET);

        // Count first: the total is what tells us whether the requested page
        // still exists, and clamping before fetching avoids serving an empty
        // table for a bookmark to a page that has since fallen off the end.
        $total = $enrollmentModel->countForList($query);
        $query->clampToTotal($total);

        $enrolled = $enrollmentModel->forList($query);

        // A one-shot result from the last bulk enrolment, if there was one.
        $bulkResult = $_SESSION[self::BULK_ENROLL_RESULT_SESSION_KEY] ?? null;
        unset($_SESSION[self::BULK_ENROLL_RESULT_SESSION_KEY]);

        $this->view('admin/enrollments', [
            'course'     => $course,
            'enrolled'   => $enrolled,
            'query'      => $query,
            'total'      => $total,
            'available'  => $enrollmentModel->studentsNotInCourse($courseId),
            'classes'    => $enrollmentModel->classesInCourse($courseId),
            'bulkClasses' => (new SchoolClass())->selectableWithCounts(),
            'bulkResult' => $bulkResult,
            // Only asked when the page came back empty, to tell "no matches"
            // apart from "nobody enrolled yet". No point paying for it otherwise.
            'anyEnrolled' => $enrolled !== [] ? true : $enrollmentModel->anyInCourse($courseId),
        ]);
    }

    // POST /admin/bulkEnroll/{courseId}. The confirmation step. Writes nothing.
    //
    // Deliberately a POST and a full page rather than a JavaScript confirm():
    // the numbers that make the decision - how many will be enrolled, how many
    // are already on the roll, how many are suspended and will be left out -
    // can only be counted on the server, and a dialog that says "are you sure?"
    // without them is not a confirmation, just a speed bump.
    public function bulkEnroll(string $courseId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/courses');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/courses');
        }

        $courseId = (int) $courseId;
        $classId  = (int) ($_POST['class_id'] ?? 0);

        $course = (new Course())->find($courseId);
        $class  = $classId > 0 ? (new SchoolClass())->find($classId) : null;

        if ($course === null) {
            $this->redirect('admin/courses');
        }
        if ($class === null) {
            $this->redirect('admin/enrollments/' . $courseId);
        }

        $preview = (new Enrollment())->bulkPreview($courseId, $classId);

        // Stashed so the write can be judged against exactly the figures on the
        // screen the admin agreed to, and so the confirm step cannot be aimed
        // at a different class than the one that was previewed.
        $_SESSION[self::BULK_ENROLL_SESSION_KEY] = [
            'course_id' => $courseId,
            'class_id'  => $classId,
            'preview'   => $preview,
        ];

        $this->view('admin/bulk_enroll_confirm', [
            'course'  => $course,
            'class'   => $class,
            'preview' => $preview,
        ]);
    }

    // POST /admin/confirmBulkEnroll/{courseId}. The only step that writes.
    public function confirmBulkEnroll(string $courseId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin/courses');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('admin/courses');
        }

        $courseId = (int) $courseId;
        $pending  = $_SESSION[self::BULK_ENROLL_SESSION_KEY] ?? null;
        unset($_SESSION[self::BULK_ENROLL_SESSION_KEY]);

        // No pending preview, or one raised for a different course: the admin
        // has gone back, refreshed, or opened two tabs. Nothing is written on a
        // guess about which class they meant.
        if ($pending === null || (int) $pending['course_id'] !== $courseId) {
            $this->redirect('admin/enrollments/' . $courseId);
        }

        $classId = (int) $pending['class_id'];
        $class   = (new SchoolClass())->find($classId);

        if ((new Course())->find($courseId) === null || $class === null) {
            $this->redirect('admin/courses');
        }

        $outcome = (new Enrollment())->enrollClass($courseId, $classId);

        // Preview and confirm are two requests, and the class can change
        // between them - a student added, suspended, or enrolled by somebody
        // else in another tab. Both sets of numbers are carried into the result
        // so that a run which did not do what the screen promised says so,
        // instead of quietly reporting the actual figure as if it had been the
        // plan all along.
        $_SESSION[self::BULK_ENROLL_RESULT_SESSION_KEY] = [
            'class_label' => SchoolClass::labelFor($class),
            'promised'    => $pending['preview'],
            'actual'      => $outcome,
        ];

        $this->redirect('admin/enrollments/' . $courseId);
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