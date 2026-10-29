<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;

/**
 * Every health check, with its status and — when it is not ok — what it said. The full width: the
 * messages are sentences.
 */
final class HealthChecks extends Widget
{
    public function id(): string
    {
        return 'health';
    }

    public function title(): string
    {
        return 'Health';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Every health check, and what each says now.';
    }

    public function size(): string
    {
        return self::FULL;
    }

    public function render(): string
    {
        $checks = Figures::health();
        if ($checks === []) {
            return '';
        }

        // The same links as the health page: a check's name opens the screen that says more.
        $links = \Pramnos\Application\Controllers\Health::checkLinks(
            array_map('strval', array_keys($checks)),
            \Pramnos\User\User::getCurrentUser() ?: null
        );

        $html = '<ul class="pf-dashboard-health">';
        foreach ($checks as $name => $result) {
            $status  = (string) ($result['status'] ?? 'unknown');
            $message = $status !== 'ok' ? (string) ($result['message'] ?? '') : '';
            $label   = isset($links[$name])
                ? '<a href="' . self::e($links[$name]) . '">' . self::e((string) $name) . '</a>'
                : self::e((string) $name);
            $html   .= '<li><span class="pf-dashboard-health-name">' . $label . '</span>'
                . ($message !== '' ? '<span class="pf-dashboard-health-message">' . self::e($message) . '</span>' : '')
                . '<span class="pf-dashboard-status pf-dashboard-status--' . self::e($status) . '">' . self::e(strtoupper($status)) . '</span></li>';
        }

        return $html . '</ul>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
