<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';
        $this->routes[] = compact('method', 'path', 'pattern', 'handler', 'middleware');
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->method;
        $path   = $request->path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            if (!preg_match($route['pattern'], $path, $matches)) continue;

            // Extract named params
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            // Run middleware
            foreach ($route['middleware'] as $mw) {
                $mwInstance = new $mw();
                $mwInstance->handle($request, $response);
            }

            // Dispatch handler
            $handler = $route['handler'];
            if (is_callable($handler)) {
                $handler($request, $response, $params);
                return;
            }

            [$controllerClass, $action] = $handler;
            $controller = new $controllerClass($request, $response);
            $controller->$action($params);
            return;
        }

        // 404
        http_response_code(404);
        $view = new View();
        $view->render('errors/404', ['layout' => 'main']);
    }
}