<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Reports the current PHP session state with sensitive values masked.
 *
 * Keys matching any of the $sensitiveKeys patterns are replaced with
 * '***' so passwords and auth tokens don't appear in the toolbar.
 *
 */
class SessionCollector implements CollectorInterface
{
    private const DEFAULT_SENSITIVE = ['password', 'auth', 'token', 'secret', 'key', 'salt'];

    /** @param list<string> $sensitiveKeys Partial key names to mask (case-insensitive). */
    public function __construct(
        private readonly array $sensitiveKeys = self::DEFAULT_SENSITIVE,
    ) {}

    public function name(): string
    {
        return 'session';
    }

    public function collect(): array
    {
        $isActive = session_status() === PHP_SESSION_ACTIVE;

        // Return early only when there is genuinely no session data at all.
        // $_SESSION may be populated manually (e.g. in tests) without a formal
        // session_start(), so we fall through and mask the data regardless.
        if (!$isActive && empty($_SESSION)) {
            return ['active' => false, 'data' => []];
        }

        $data = [];
        foreach ($_SESSION as $key => $value) {
            $data[$key] = $this->isSensitive((string) $key)
                ? '***'
                : (is_scalar($value) ? $value : gettype($value) . '(...)');
        }

        return [
            'active'     => $isActive,
            'session_id' => $isActive ? session_id() : '',
            'count'      => count($_SESSION),
            'data'       => $data,
        ];
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->sensitiveKeys as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }
}
