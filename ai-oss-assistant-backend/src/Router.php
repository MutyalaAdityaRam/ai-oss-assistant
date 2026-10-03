<?php

namespace AiOssAssistant;

use AiOssAssistant\Config;
use Throwable;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, bool $requiresAuth = true): void
    {
        $this->routes[] = [
            'method'       => strtoupper($method),
            'path'         => $path,
            'handler'      => $handler,
            'requiresAuth' => $requiresAuth,
        ];
    }

    public function get(string $path, callable|array $handler, bool $requiresAuth = true): void
    {
        $this->add('GET', $path, $handler, $requiresAuth);
    }

    public function post(string $path, callable|array $handler, bool $requiresAuth = true): void
    {
        $this->add('POST', $path, $handler, $requiresAuth);
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path   = parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                // Auth check for protected routes
                if ($route['requiresAuth'] && !$this->authenticate()) {
                    $this->jsonResponse(['error' => 'Unauthorized: Invalid or missing Bearer token'], 401);
                    return;
                }

                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                try {
                    $handler = $route['handler'];
                    if (is_array($handler)) {
                        [$controllerClass, $methodName] = $handler;
                        $controller = new $controllerClass();
                        $response = $controller->$methodName($params);
                    } else {
                        $response = call_user_func($handler, $params);
                    }

                    if (is_array($response) || is_object($response)) {
                        $this->jsonResponse($response);
                    }
                    return;
                } catch (Throwable $e) {
                    $code = is_numeric($e->getCode()) ? (int)$e->getCode() : 500;
                    $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
                    $this->jsonResponse([
                        'error' => $e->getMessage(),
                        'trace' => Config::getBool('APP_DEBUG') ? $e->getTraceAsString() : null,
                    ], $statusCode);
                    return;
                }
            }
        }

        $this->jsonResponse(['error' => 'Route not found: ' . $path], 404);
    }

    public function authenticate(): bool
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $token = trim(substr($authHeader, 7));
        $expectedToken = Config::get('API_BEARER_TOKEN', 'dev_secret_token_12345');

        return hash_equals($expectedToken, $token);
    }

    public function jsonResponse(mixed $data, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
