<?php
/**
 * Global helpers. Required once from public/index.php, before the autoloader,
 * because these are plain functions and the autoloader only resolves classes.
 *
 * Keep this file small. Anything with state belongs in a class.
 */

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output. Null-safe.
     *
     * Passing null to htmlspecialchars is deprecated from PHP 8.1 and becomes
     * an error later, so every value is coerced through (string) first. That
     * matters here because several columns are legitimately NULL - email for
     * every student, admission_no for every member of staff, class_id for a
     * student not yet placed - and a view should not have to remember which.
     *
     * ENT_QUOTES escapes single as well as double quotes, so the result is
     * safe inside either kind of attribute. Named explicitly rather than left
     * to the default, which differs across PHP versions.
     *
     * @param mixed $value
     */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
