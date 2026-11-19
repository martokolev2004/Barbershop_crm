<?php

declare(strict_types=1);

namespace BarberLead;

final class View
{
    /** @param array<string,mixed> $shared variables available in every template (user, csrf, flashes...) */
    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $vars);
        if ($layout === null) {
            return $content;
        }
        return $this->partial($layout, $vars + ['content' => $content]);
    }

    /** @param array<string,mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        $view = $this;
        extract($this->shared + $vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
