<?php
/**
 * The enrolled-students list for one course: search, filter, sort and
 * pagination, resolved once.
 *
 * Same shape and the same two rules as UserListQuery - read that one first,
 * this is deliberately its sibling rather than its own invention:
 *
 *   Nothing from the request ever reaches SQL as text. Values are bound;
 *   identifiers (the sort column, the direction) are looked up in a hardcoded
 *   allowlist and the request value is only ever used as an array key.
 *
 *   The WHERE clause is built once, by where(), and both the COUNT and the
 *   page query use that same fragment with those same bindings.
 *
 * The one structural difference: this list is always scoped to a course, and
 * the course is not a filter. It arrives as a route segment, not as request
 * data, so it is a constructor argument of its own rather than something read
 * out of $request - and it is the one clause where() always emits. It stays
 * out of the link params too, because it already lives in the path.
 */
final class EnrollmentListQuery
{
    /**
     * Sortable columns. The request sends a key; the value is a real column
     * name that only ever appears in SQL from this literal array. An unknown
     * key falls back to the default sort silently, so a stale bookmark shows a
     * sensible list rather than a failure.
     *
     * Sorting by class means year group then arm, for the same reason as the
     * users list: without the arm, SS3A/SS3B/SS3C interleave and fall through
     * to the u.id tiebreaker, which is stable but meaningless to read.
     *
     * There is no "enrolled on" entry. The enrollments table is a bare
     * (student_id, course_id) pair with no timestamp, and this does not
     * pretend otherwise.
     */
    public const SORTS = [
        'name'         => ['u.full_name'],
        'admission_no' => ['u.admission_no'],
        'class'        => ['c.year_group', 'c.arm'],
        'status'       => ['u.status'],
    ];

    /**
     * Used when no sort is requested: class, then name - the order a register
     * is read in.
     *
     * c.year_group is an ENUM declared 'JSS1','JSS2','JSS3','SS1','SS2','SS3',
     * 'UNASSIGNED', and MySQL orders an ENUM by that declared ordinal rather
     * than alphabetically, so this is genuine educational order.
     *
     * Students with no class sort last: NULL year_group would otherwise lead.
     */
    private const DEFAULT_ORDER = 'CASE WHEN u.class_id IS NULL THEN 1 ELSE 0 END ASC, '
                                . 'c.year_group ASC, c.arm ASC, u.full_name ASC';

    public const STATUSES  = ['active', 'suspended'];
    public const PER_PAGES = [25, 50, 100];

    // Same escape character and reasoning as UserListQuery: a literal % or _
    // typed into the search box matches itself. '!' needs no escaping in a PHP
    // string or a SQL literal and is unaffected by NO_BACKSLASH_ESCAPES.
    private const LIKE_ESCAPE = '!';

    public int $courseId;
    public string $q;
    public string $status;      // '' means any
    public ?int $classId;
    public ?string $sort;       // null means "no sort requested" -> default
    public string $dir;         // exactly 'ASC' or 'DESC'
    public int $page;
    public int $perPage;

    /** Every param, normalised, for rebuilding links. */
    private array $params;

    public function __construct(int $courseId, array $request)
    {
        // Not from $request, and not optional. The controller has already
        // looked this course up and bounced the request if it does not exist.
        $this->courseId = $courseId;

        $this->q = trim((string) ($request['q'] ?? ''));
        if (mb_strlen($this->q) > 100) {
            $this->q = mb_substr($this->q, 0, 100);
        }

        // Unlike the users list, the default here is "everyone enrolled".
        // A course roll that quietly hid suspended students would misreport
        // who is on it, and those rows are exactly what an admin has come to
        // this page to find when they are tidying one up.
        $requestedStatus = (string) ($request['status'] ?? '');
        $this->status = in_array($requestedStatus, self::STATUSES, true) ? $requestedStatus : '';

        $classId = (int) ($request['class_id'] ?? 0);
        $this->classId = $classId > 0 ? $classId : null;

        $requestedSort = (string) ($request['sort'] ?? '');
        $this->sort = isset(self::SORTS[$requestedSort]) ? $requestedSort : null;

        // Anything that is not exactly 'desc' is ascending. There is no third
        // state, so this cannot produce a value outside {ASC, DESC}.
        $this->dir = strtolower((string) ($request['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

        $perPage = (int) ($request['per_page'] ?? 50);
        $this->perPage = in_array($perPage, self::PER_PAGES, true) ? $perPage : 50;

        $this->page = max(1, (int) ($request['page'] ?? 1));

        // course_id is absent on purpose: it is in the path, so carrying it in
        // the query string as well would let a hand-edited link disagree with
        // itself about which course is being shown.
        $this->params = [
            'q'        => $this->q,
            'status'   => $this->status,
            'class_id' => $this->classId,
            'sort'     => $this->sort,
            'dir'      => strtolower($this->dir),
            'page'     => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    /** True when anything narrows the list beyond the default view. */
    public function hasActiveFilters(): bool
    {
        return $this->q !== '' || $this->status !== '' || $this->classId !== null;
    }

    /**
     * The WHERE clause and its bindings, built once and used by both the count
     * query and the page query.
     *
     * @return array{0: string, 1: array} [' WHERE ...', bindings]
     */
    public function where(): array
    {
        // Always present. Every other clause narrows within one course.
        $clauses  = ['e.course_id = ?'];
        $bindings = [$this->courseId];

        if ($this->q !== '') {
            $term = '%' . $this->escapeLike($this->q) . '%';

            // Name and admission number only. Every row on this list is a
            // student, and the schema's CHECK constraint guarantees a student's
            // email is NULL, so an email arm could never match anything here.
            //
            // COALESCE for the same reason as the users list: `NULL LIKE '%x%'`
            // is NULL, not false, and coalescing to '' keeps each arm a plain
            // true/false rather than leaving a NULL for a later NOT to invert.
            $clauses[] = "(COALESCE(u.full_name, '')    LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                       . " OR COALESCE(u.admission_no, '') LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "')";

            $bindings[] = $term;
            $bindings[] = $term;
        }

        if ($this->status !== '') {
            $clauses[] = 'u.status = ?';
            $bindings[] = $this->status;
        }

        if ($this->classId !== null) {
            $clauses[] = 'u.class_id = ?';
            $bindings[] = $this->classId;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * The ORDER BY clause. Built from the allowlist above, never from request
     * text, and always closed with u.id so that rows with equal sort values
     * keep a fixed order between pages instead of shuffling.
     */
    public function orderBy(): string
    {
        if ($this->sort === null) {
            $expression = self::DEFAULT_ORDER;
        } else {
            // Every column in the chosen entry takes the requested direction.
            // $dir is already known to be exactly 'ASC' or 'DESC', and the
            // column names come from the literal array above, never the request.
            $dir = $this->dir;
            $expression = implode(', ', array_map(
                static fn(string $column): string => $column . ' ' . $dir,
                self::SORTS[$this->sort]
            ));
        }

        return ' ORDER BY ' . $expression . ', u.id ASC';
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Pull the page back to the last one that exists. Called once the total is
     * known, so a bookmark to page 9 of a roll that has shrunk to 2 shows page
     * 2 rather than an empty table.
     */
    public function clampToTotal(int $total): void
    {
        $lastPage = max(1, (int) ceil($total / $this->perPage));

        if ($this->page > $lastPage) {
            $this->page = $lastPage;
            $this->params['page'] = $lastPage;
        }
    }

    public function lastPage(int $total): int
    {
        return max(1, (int) ceil($total / $this->perPage));
    }

    /**
     * The current query string with some params replaced - the one helper every
     * sort link, page link and the filter form build their URLs from, so no
     * link can quietly drop a filter that is in force.
     *
     * Pass null as a value to remove a param.
     */
    public function urlWith(array $overrides = []): string
    {
        $params = array_merge($this->params, $overrides);

        // Drop empties so a clean list has a clean URL rather than a tail of
        // q=&class_id=&sort=.
        $params = array_filter(
            $params,
            static fn($v) => $v !== null && $v !== '' && $v !== false
        );

        return $params === [] ? '' : '?' . http_build_query($params);
    }

    /** The href for a sortable column header, toggling direction on the active one. */
    public function sortUrl(string $key): string
    {
        $isActive = $this->sort === $key;

        return $this->urlWith([
            'sort' => $key,
            'dir'  => $isActive && $this->dir === 'ASC' ? 'desc' : 'asc',
            'page' => 1,   // a re-sorted list starts again at the top
        ]);
    }

    public function sortIndicator(string $key): string
    {
        if ($this->sort !== $key) {
            return '';
        }

        return $this->dir === 'ASC' ? '▲' : '▼';
    }

    // Escape the LIKE metacharacters so they match themselves. The escape
    // character has to be escaped first, or escaping % would double-escape it.
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }
}
