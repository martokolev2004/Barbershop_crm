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
        return $this->renderFile($file, $this->shared + $vars);
    }

    /**
     * Isolated scope: only the template variables exist here, so names like $template or $file in
     * the data can never collide with this method's own variables.
     * @param array<string,mixed> $__vars
     */
    private function renderFile(string $__file, array $__vars): string
    {
        $view = $this;
        extract($__vars, EXTR_SKIP);
        unset($__vars);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $__e) {
            ob_end_clean();
            throw $__e;
        }
        return (string) ob_get_clean();
    }
}
