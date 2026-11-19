<?php

declare(strict_types=1);

namespace BarberLead\Http;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $wantsJson = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        $post = $_POST;
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $post = is_array($decoded) ? $decoded : [];
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $post,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $wantsJson,
        );
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->str($key);
        return preg_match('/^-?\d+$/', $v) ? (int) $v : $default;
    }
}
