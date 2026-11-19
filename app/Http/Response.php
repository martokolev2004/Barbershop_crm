<?php

declare(strict_types=1);

namespace BarberLead\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function redirect(string $to, int $status = 303): self
    {
        // Only local paths: prevents open redirects through ?next= parameters.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        return new self('', $status, ['Location' => $to]);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $defaults = [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; frame-src https://www.openstreetmap.org; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
            'Cache-Control' => 'no-store',
        ];
        foreach ($this->headers + $defaults as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
