<?php

declare(strict_types=1);

namespace BarberLead\Http;

/** Maps method + path to a route name. Dispatch is an explicit match in App (no dynamic calls). */
final class Router
{
    /** @var list<array{string,string,string}> */
    private array $routes = [];

    public function get(string $pattern, string $name): void
    {
        $this->routes[] = ['GET', $pattern, $name];
    }

    public function post(string $pattern, string $name): void
    {
        $this->routes[] = ['POST', $pattern, $name];
    }

    /** @return array{string, int}|null [route name, numeric id (0 when the route has none)] */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as [$m, $pattern, $name]) {
            if ($m !== $method) {
                continue;
            }
            $regex = '#^' . str_replace('{id}', '(\d+)', $pattern) . '$#';
            if (preg_match($regex, $path, $mm)) {
                return [$name, isset($mm[1]) ? (int) $mm[1] : 0];
            }
        }
        return null;
    }
}
