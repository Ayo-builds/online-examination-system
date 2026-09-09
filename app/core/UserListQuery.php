<?php
/**
 * The admin Users list: search, filter, sort and pagination, resolved once.
 *
 * Everything the list needs is derived here from the request, validated here,
 * and handed to the model as SQL fragments plus bindings. Two rules hold the
 * whole thing together:
 *
 *   Nothing from the request ever reaches SQL as text. Values are bound;
 *   identifiers (the sort column, the direction) are looked up in a hardcoded
 *   allowlist and the request value is only ever used as an array key.
 *
 *   The WHERE clause is built once, by where(), and both the COUNT and the
 *   page query use that same fragment with those same bindings. They cannot
 *   drift apart, so the page numbers always describe the rows being shown.
 */
final class UserListQuery
{
    /**
     * Sortable columns. The request sends a key; the value is a real column
     * name that only ever appears in SQL from this literal array. An unknown
     * key is not an error - it falls back to the default sort silently,
     * because a stale bookmark should show a sensible list, not a failure.
     *
     * Each value is a list of columns, applied in order and all taking the
     * requested direction. Sorting by class means year group then arm: without
     * the arm, SS3A/SS3B/SS3C would interleave and fall through to the u.id
     * tiebreaker, which is stable but meaningless to read.
     *
     * The multi-column default below is still a separate expression rather than
     * an entry here, because it mixes directions and a CASE.
     */
    public const SORTS = [
        'name'         => ['u.full_name'],
        'admission_no' => ['u.admission_no'],
        'class'        => ['c.year_group', 'c.arm'],
        'role'         => ['u.role'],
        'status'       => ['u.status'],
        'joined'       => ['u.created_at'],
    ];

    /**
     * Used when no sort is requested: class, then name.
     *
     * c.year_group is an ENUM declared 'JSS1','JSS2','JSS3','SS1','SS2','SS3',
     * 'UNASSIGNED', and MySQL orders an ENUM by that declared ordinal rather
     * than alphabetically. So this is genuine educational order - JSS3 really
     * does sort before SS1 - and it stays correct if a year group is ever
     * inserted into the middle of the ENUM. There is no rank column on
     * classes, and this does not pretend there is one.
     *
     * Students with no class sort last: NULL year_group would otherwise lead.
     */
    private const DEFAULT_ORDER = 'CASE WHEN u.class_id IS NULL THEN 1 ELSE 0 END ASC, '
                                . 'c.year_group ASC, c.arm ASC, u.full_name ASC';

    public const ROLES     = ['student', 'lecturer', 'admin'];
    public const PER_PAGES = [25, 50, 100];

    // Staff have no class, so a class filter alongside a staff role would
    // always return nothing. Requirement: ignore it and hide the control.
    private const STAFF_ROLES = ['lecturer', 'admin'];

    // A literal % or _ typed into the search box must match itself, not act as
    // a wildcard. '!' is the escape character rather than the conventional
    // backslash: it needs no escaping in a PHP string or a SQL literal, and it
    // is unaffected by the NO_BACKSLASH_ESCAPES sql_mode.
    private const LIKE_ESCAPE = '!';

    public string $q;
    public string $role;
    public ?int $classId;
    public ?string $sort;       // null means "no sort requested" -> default
    public string $dir;         // exactly 'ASC' or 'DESC'
    public int $page;
    public int $perPage;

    /** Every param, normalised, for rebuilding links. */
    private array $params;

    public function __construct(array $request)
    {
        $this->q = trim((string) ($request['q'] ?? ''));
        if (mb_strlen($this->q) > 100) {
            $this->q = mb_substr($this->q, 0, 100);
        }

        // Default is students only. Anything not on the allowlist, including
        // an explicit '' meaning "everyone", is honoured only if it is exactly
        // 'all'; a junk value falls back to the default rather than silently
        // widening the list.
        $requestedRole = (string) ($request['role'] ?? 'student');
        if ($requestedRole === 'all') {
            $this->role = '';
        } elseif (in_array($requestedRole, self::ROLES, true)) {
            $this->role = $requestedRole;
        } else {
            $this->role = 'student';
        }

        $classId = (int) ($request['class_id'] ?? 0);
        $this->classId = ($classId > 0 && !$this->isStaffFilter()) ? $classId : null;

        $requestedSort = (string) ($request['sort'] ?? '');
        $this->sort = isset(self::SORTS[$requestedSort]) ? $requestedSort : null;

        // Anything that is not exactly 'desc' is ascending. There is no third
        // state, so this cannot produce a value outside {ASC, DESC}.
        $this->dir = strtolower((string) ($request['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

        $perPage = (int) ($request['per_page'] ?? 50);
        $this->perPage = in_array($perPage, self::PER_PAGES, true) ? $perPage : 50;

        $this->page = max(1, (int) ($request['page'] ?? 1));

        $this->params = [
            'q'        => $this->q,
            'role'     => $this->role === '' ? 'all' : $this->role,
            'class_id' => $this->classId,
            'sort'     => $this->sort,
            'dir'      => strtolower($this->dir),
            'page'     => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    public function isStaffFilter(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** True when anything narrows the list beyond the default view. */
    public function hasActiveFilters(): bool
    {
        return $this->q !== '' || $this->classId !== null || $this->role !== 'student';
    }

    /**
     * The WHERE clause and its bindings, built once and used by both the count
     * query and the page query.
     *
     * @return array{0: string, 1: array} [' WHERE ...' or '', bindings]
     */
    public function where(): array
    {
        $clauses  = [];
        $bindings = [];

        if ($this->q !== '') {
            $term = '%' . $this->escapeLike($this->q) . '%';

            // COALESCE, not a bare OR chain. email is NULL for every student
            // and admission_no is NULL for every member of staff, and in SQL
            // `NULL LIKE '%x%'` is NULL rather than false. A bare chain happens
            // to behave correctly today - TRUE OR NULL is TRUE, so a student
            // still matches on their name - but it is one NOT away from
            // excluding exactly the rows it should return. Coalescing to ''
            // makes each arm a plain true/false and removes the trap.
            $clauses[] = "(COALESCE(u.full_name, '')    LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                       . " OR COALESCE(u.admission_no, '') LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                       . " OR COALESCE(u.email, '')        LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "')";

            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if ($this->role !== '') {
            $clauses[] = 'u.role = ?';
            $bindings[] = $this->role;
        }

        // Already forced to null in the constructor when the role is staff, so
        // this cannot produce the always-empty combination.
        if ($this->classId !== null) {
            $clauses[] = 'u.class_id = ?';
            $bindings[] = $this->classId;
        }

        $sql = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$sql, $bindings];
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
     * known, so a bookmark to page 40 of a list that has shrunk to 3 shows
     * page 3 rather than an empty table.
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
