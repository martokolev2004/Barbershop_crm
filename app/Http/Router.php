<?php

declare(strict_types=1);

namespace BarberLead\Http;

final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /** @return array{callable, array<string,string>}|null  null = 404; method mismatch handled as 404 too */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as [$m, $pattern, $handler]) {
            if ($m !== $method) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
            if (preg_match($regex, $path, $mm)) {
                return [$handler, array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        return null;
    }
}
