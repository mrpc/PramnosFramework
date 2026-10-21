<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * What the audience tools share: each answers only where its tables are installed, and only to an
 * administrator with the screen's ability — the one the Segments, Rules or A/B tests screen asks.
 * Without an administrator (the MCP server, which is authorised on its own) the ability is not
 * asked.
 */
abstract class AudienceTool extends AdminTool
{
    /** @param int $adminId The administrator asking; 0 for none */
    public function __construct(protected int $adminId = 0)
    {
    }

    /** The tables this tool reads, by name. @return list<string> */
    abstract protected function tables(): array;

    /** The ability the screen it reads asks. */
    abstract protected function ability(): string;

    /** The tool's own arguments: no date window. */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => $this->arguments()];
    }

    public function execute(array $input): mixed
    {
        if (!\Pramnos\Audience\Installed::has(...$this->tables())) {
            return self::absent(implode(', ', $this->tables()));
        }
        if (!$this->allowed($this->ability())) {
            return ['available' => false, 'says' => 'The administrator asking may not use this screen (' . $this->ability() . ').'];
        }
        try {
            return $this->answer($input, Window::from([]));
        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /** Whether the administrator asking has an ability; true where nobody is asking. */
    protected function allowed(string $ability): bool
    {
        if ($this->adminId <= 0) {
            return true;
        }
        $user = new \Pramnos\User\User($this->adminId);

        return \Pramnos\Auth\AdminAccess::allows($user, $ability, \Pramnos\Auth\AdminAccess::defaultUsertype());
    }

    /** An id argument, or 0. @param array<string, mixed> $input */
    protected static function id(array $input, string $key): int
    {
        return max(0, (int) ($input[$key] ?? 0));
    }
}
