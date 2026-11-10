<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    public readonly string $method;
    public readonly string $uri;
    public readonly string $path;
    public readonly array  $query;
    public readonly array  $post;
    public readonly array  $files;
    public readonly array  $headers;
    public readonly string $ip;
    public readonly string $userAgent;

    public function __construct()
    {
        $this->method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri          = $_SERVER['REQUEST_URI'] ?? '/';
        $parsed          = parse_url($rawUri);
        $this->uri       = $rawUri;
        $this->path      = rtrim($parsed['path'] ?? '/', '/') ?: '/';
        parse_str($parsed['query'] ?? '', $q);
        $this->query     = $q;
        $this->post      = $_POST;
        $this->files     = $_FILES;
        $this->ip        = $this->resolveIp();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->headers   = $this->resolveHeaders();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    private function resolveIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        return $_SERVER['HTTP_' . $key] ?? null;
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    private function resolveHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $val;
            }
        }
        return $headers;
    }
}