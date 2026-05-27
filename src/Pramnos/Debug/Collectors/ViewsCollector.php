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
 * @package PramnosFramework
 */
class ViewsCollector implements CollectorInterface
{
    /** @var list<array{view: string, template: string, render_ms: float}> */
    private array $views = [];

    public function name(): string
    {
        return 'views';
    }

    public function record(string $viewName, string $tplFile, float $renderMs): void
    {
        $this->views[] = [
            'view'      => $viewName,
            'template'  => basename($tplFile),
            'render_ms' => round($renderMs, 2),
        ];
    }

    public function collect(): array
    {
        return [
            'count' => count($this->views),
            'views' => $this->views,
        ];
    }
}
