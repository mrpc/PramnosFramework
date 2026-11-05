<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Skills an application writes as files: a specialist's method, turned on by the model when a
 * question needs it, and the tools that go with it.
 *
 * ```php
 * // app/app.php
 * 'llm' => ['chat' => ['skills_path' => 'app/skills']],
 * ```
 *
 * ```markdown
 * ---
 * name: crm-loyalty
 * description: Retention and loyalty: who is drifting away, and what to do for each
 * tools: [audience_segments, audience_why, retention]
 * ---
 * # How a CRM and loyalty marketer works
 * Start from the cohort, not the campaign. …
 * ```
 *
 * Each `<slug>.md` in the folder is one skill — the Agent Skills pattern. `name` (the file's name
 * when it has none) is what `use_skill` takes; `description` is the line the model chooses by;
 * `tools` are the tools it opens, beside those whose metadata already names the skill; and the
 * body is handed to the model when the skill is turned on, so the method costs nothing in a
 * conversation that does not need it. A skill of instructions only, with no tool, is offered too.
 *
 * The files are the application's configuration, trusted as its system prompt is: whoever can
 * write them can already change what the model is told.
 */
final class Skills
{
    /**
     * The skills in the application's folder, by name; none without one.
     *
     * @return array<string, array{description: string, tools: list<string>, instructions: string}>
     */
    public static function load(?string $folder = null): array
    {
        $folder ??= self::folder();
        if ($folder === null || !is_dir($folder)) {
            return [];
        }
        $skills = [];
        foreach (glob(rtrim($folder, '/') . '/*.md') ?: [] as $file) {
            ['meta' => $meta, 'body' => $body] = \Pramnos\Support\FrontMatter::parse((string) file_get_contents($file));
            $name = is_string($meta['name'] ?? null) && $meta['name'] !== '' ? (string) $meta['name'] : basename($file, '.md');
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $name) !== 1) {
                \Pramnos\Logs\Logger::log('Skill file ' . basename($file) . ': "' . $name . '" is not a name a skill can have; it was left out.', 'llm');
                continue;
            }
            $skills[$name] = [
                'description'  => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                'tools'        => \Pramnos\Support\FrontMatter::names($meta['tools'] ?? []),
                'instructions' => $body,
            ];
        }
        ksort($skills);

        return $skills;
    }

    /** The folder app.php names — `llm.chat.skills_path`, under the application's root — or null. */
    public static function folder(): ?string
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);
        $path = is_array($chat) ? trim((string) ($chat['skills_path'] ?? '')) : '';
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, '/') ? $path : (defined('ROOT') ? ROOT . '/' : '') . $path;
    }
}
