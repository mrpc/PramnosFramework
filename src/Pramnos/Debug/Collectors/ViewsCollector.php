<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects view templates rendered during the current request.
 *
 * View::getTpl() calls record() each time a template file is successfully
 * included. Only the basename of the path is stored to avoid leaking the
 * full server file-system layout.
 *
 */
class ViewsCollector implements CollectorInterface
{
    /** @var list<array{view: string, template: string, render_ms: float}> */
    private array $views = [];

    public function name(): string
    {
        return 'views';
    }

    public function record(string $viewName, string $tplFile, float $renderMs, bool $fromCache = false): void
    {
        $this->views[] = [
            'view'       => $viewName,
            'template'   => basename($tplFile),
            'render_ms'  => round($renderMs, 2),
            'from_cache' => $fromCache,
        ];
    }

    public function collect(): array
    {
        $cached = count(array_filter($this->views, fn($v) => $v['from_cache']));
        return [
            'count'  => count($this->views),
            'cached' => $cached,
            'views'  => $this->views,
        ];
    }
}
