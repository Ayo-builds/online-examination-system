<?php
class Router
{
    public function dispatch(string $url): void
    {
        $segments = self::segments($url);

        // Segment 1: which controller? (default: home)
        $controllerName = ucfirst(strtolower($segments[0] ?? 'home')) . 'Controller';

        // Segment 2: which method on that controller? (default: index)
        $method = $segments[1] ?? 'index';

        // Segments 3+: parameters passed to the method
        $params = array_slice($segments, 2);

        if (!class_exists($controllerName)) {
            $this->abort404();
        }

        $controller = new $controllerName();

        if (!method_exists($controller, $method)) {
            $this->abort404();
        }

        call_user_func_array([$controller, $method], $params);
    }

    /**
     * "controller/method" for a URL, lower-cased: 'Student//HeartBeat/9/'
     * gives 'student/heartbeat'. PHP finds methods whatever their case, so
     * that URL reaches heartbeat() and must be named the same way.
     * ErrorHandler uses this to know a JSON route from a page route before
     * any controller runs, so both read the URL with the one parser.
     */
    public static function routeKey(string $url): string
    {
        $segments = self::segments($url);

        return strtolower($segments[0] ?? 'home') . '/' . strtolower($segments[1] ?? 'index');
    }

    /** "exam/start/5" -> ['exam', 'start', '5'] */
    private static function segments(string $url): array
    {
        return array_values(array_filter(explode('/', trim($url, '/'))));
    }

    private function abort404(): void
    {
        ErrorPage::show(404, 'Page not found.');
    }
}
