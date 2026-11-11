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
 * audience: adult            # child, adult or all (the default) — see Audience
 * ---
 * # How a CRM and loyalty marketer works
 * Start from the cohort, not the campaign. …
 * ```
 *
 * Each `<slug>.md` in the folder is one skill — the Agent Skills pattern — or a `<slug>/SKILL.md`
 * with its `references/` and `evals/` beside it ({@see load()}). `name` (the file's name
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
    /** @var array<string, true> Skills whose unknown tools have been logged in this process */
    private static array $saidUnknown = [];

    /** The tool that reads a skill's reference file, offered while a skill that has any is on. */
    public const READER = 'read_skill_reference';

    /** The largest reference file handed to the model, in bytes. */
    public const REFERENCE_MAX = 65536;

    /**
     * The skills in the application's folder, by name; none without one.
     *
     * A skill is a file, `<slug>.md`, or a folder, `<slug>/SKILL.md` — the shape public Agent Skills
     * come in — beside its `references/` (`*.md`, `*.txt`: details read on demand, not handed over
     * with the skill) and its `evals/` (`*.json` cases for `llm:eval --skills`).
     *
     * A skill taken from a catalogue is left out while its {@see SkillLock} entry is off — unless
     * `$evenOff`, for the commands that list and govern them.
     *
     * @return array<string, array{description: string, tools: list<string>, instructions: string, folder: string|null, references: array<string, string>, evals: string|null, enabled: bool}>
     */
    public static function load(?string $folder = null, bool $evenOff = false): array
    {
        $folder ??= self::folder();
        if ($folder === null || !is_dir($folder)) {
            return [];
        }
        $root   = rtrim($folder, '/');
        // Skills taken from a catalogue are off until a person has read them (SkillLock).
        $lock   = new SkillLock($root);
        $skills = [];
        $found  = array_merge(
            array_map(static fn (string $f): array => [$f, null], glob($root . '/*.md') ?: []),
            array_map(static fn (string $f): array => [$f, dirname($f)], glob($root . '/*/SKILL.md') ?: [])
        );
        foreach ($found as [$file, $own]) {
            ['meta' => $meta, 'body' => $body] = \Pramnos\Support\FrontMatter::parse((string) file_get_contents($file));
            $fallback = $own !== null ? basename($own) : basename($file, '.md');
            $name     = is_string($meta['name'] ?? null) && $meta['name'] !== '' ? (string) $meta['name'] : $fallback;
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $name) !== 1) {
                \Pramnos\Logs\Logger::log('Skill file ' . basename($file) . ': "' . $name . '" is not a name a skill can have; it was left out.', 'llm');
                continue;
            }
            $enabled = $own === null || $lock->allows(basename($own));
            if (!$enabled && !$evenOff) {
                continue;
            }
            $skills[$name] = [
                'description'  => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                'tools'        => \Pramnos\Support\FrontMatter::names($meta['tools'] ?? []),
                'instructions' => $body,
                'folder'       => $own,
                'references'   => $own !== null ? self::references($own) : [],
                'evals'        => $own !== null && is_dir($own . '/evals') ? $own . '/evals' : null,
                'enabled'      => $enabled,
                // Whom it is for: child, adult or all (the default); and for a child, which ages (`3-7`).
                'audience'     => in_array($meta['audience'] ?? null, Audience::ALL, true) ? (string) $meta['audience'] : Audience::EVERYONE,
                'age'          => is_scalar($meta['age'] ?? null) ? trim((string) $meta['age']) : '',
            ];
        }
        ksort($skills);

        return $skills;
    }

    /**
     * What `use_skill` hands over for a skill: its body, then the list of its references, which the
     * model reads with {@see READER} only when it needs one.
     *
     * @param array{instructions: string, references: array<string, string>} $skill
     */
    public static function instructions(array $skill): string
    {
        if ($skill['references'] === []) {
            return $skill['instructions'];
        }
        $lines = [];
        foreach ($skill['references'] as $file => $title) {
            $lines[] = '- ' . $file . ($title !== '' ? ': ' . $title : '');
        }

        return rtrim($skill['instructions'] . "\n\nReferences — read one with " . self::READER . " when you need it:\n" . implode("\n", $lines));
    }

    /**
     * Declare the skills on a registry, with {@see READER} for those that have references — offered
     * only while one of them is on.
     *
     * @param array<string, array<string, mixed>>|null $skills As {@see load()} gives them; null to load
     */
    public static function declare(\Pramnos\Llm\ToolRegistry $registry, ?array $skills = null): \Pramnos\Llm\ToolRegistry
    {
        $skills ??= self::load();
        $withReferences = [];
        foreach ($skills as $name => $skill) {
            // A tool a skill names that nobody declared is said, once — a misspelt name otherwise
            // makes a skill open less than its author meant, without a word.
            $unknown = array_values(array_filter($skill['tools'], static fn (string $tool): bool => !$registry->has($tool)));
            if ($unknown !== [] && !isset(self::$saidUnknown[$name])) {
                self::$saidUnknown[$name] = true;
                \Pramnos\Logs\Logger::log('Skill ' . $name . ' names tools nobody declared: ' . implode(', ', $unknown) . '.', 'llm');
            }
            $registry->defineSkill($name, $skill['description'], $skill['tools'], self::instructions($skill));
            if (($skill['references'] ?? []) !== []) {
                $withReferences[$name] = $skill;
            }
        }
        if ($withReferences !== []) {
            $registry->register(
                self::READER,
                static fn (array $arguments): string => self::read($withReferences, (string) ($arguments['skill'] ?? ''), (string) ($arguments['file'] ?? '')),
                ['skills' => array_keys($withReferences), 'idempotent' => true],
                'Read one of the reference files a skill you turned on lists.',
                ['properties' => [
                    'skill' => ['type' => 'string', 'enum' => array_keys($withReferences)],
                    'file'  => ['type' => 'string', 'description' => 'The file name, as the skill lists it'],
                ], 'required' => ['skill', 'file']]
            );
        }

        return $registry;
    }

    /**
     * One reference file of a skill — only one its folder's `references/` listed, only inside that
     * folder once links are followed, only up to {@see REFERENCE_MAX}. A skill kept in the database
     * ({@see CatalogSkills}) brings its references' text as `bodies`.
     *
     * @param array<string, array<string, mixed>> $skills
     */
    public static function read(array $skills, string $skill, string $file): string
    {
        $own = $skills[$skill] ?? null;
        if ($own === null || !isset($own['references'][$file])) {
            return 'Error: ' . $skill . ' has no reference called ' . $file . '.';
        }
        if (isset($own['bodies'][$file])) {
            $text = (string) $own['bodies'][$file];

            return strlen($text) > self::REFERENCE_MAX ? substr($text, 0, self::REFERENCE_MAX) . "\n\n[Cut at " . self::REFERENCE_MAX . ' bytes.]' : $text;
        }
        $folder = realpath((string) $own['folder'] . '/references');
        $path   = realpath((string) $own['folder'] . '/references/' . $file);
        if ($folder === false || $path === false || !str_starts_with($path, $folder . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return 'Error: ' . $file . ' cannot be read.';
        }
        $size = (int) filesize($path);
        $text = (string) file_get_contents($path, false, null, 0, self::REFERENCE_MAX);

        return $size > self::REFERENCE_MAX ? $text . "\n\n[Cut at " . self::REFERENCE_MAX . ' bytes.]' : $text;
    }

    /**
     * A skill folder's reference files, by name, with each one's title: its first heading, or its
     * first line.
     *
     * @return array<string, string>
     */
    private static function references(string $own): array
    {
        $out = [];
        foreach (array_merge(glob($own . '/references/*.md') ?: [], glob($own . '/references/*.txt') ?: []) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $head  = (string) file_get_contents($file, false, null, 0, 2048);
            $body  = \Pramnos\Support\FrontMatter::parse($head)['body'];
            $title = preg_match('/^#+\s+(.+)$/m', $body, $m) === 1 ? trim($m[1]) : trim((string) strtok($body, "\n"));
            $out[basename($file)] = mb_substr($title, 0, 120);
        }
        ksort($out);

        return $out;
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
