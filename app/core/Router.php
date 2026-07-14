<?php
class Router
{
    public function dispatch(string $url): void
    {
        // "exam/start/5" -> ['exam', 'start', '5']
        $segments = array_values(array_filter(explode('/', trim($url, '/'))));

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

    private function abort404(): void
    {
        http_response_code(404);
        exit('404 — Page not found');
    }
}