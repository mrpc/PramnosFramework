<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * What the skills taken from catalogues are: `skills.lock.json` in the skills folder — for each, its
 * source, the commit it was taken at, the hash of its files then, its licence, and whether a person
 * has read it and turned it on.
 *
 * ```json
 * {"crm-loyalty": {"source": "owner/repo/skills/crm", "commit": "…", "hash": "…", "licence": "MIT",
 *                  "enabled": false, "approved_hash": null, "added_at": "2026-11-05T10:00:00+00:00"}}
 * ```
 *
 * A skill taken from somebody else is instructions to a model that can reach the application's data,
 * so it is off until somebody reads it (`skill:enable`), and off again whenever its text is replaced
 * from upstream. A skill with no entry is the application's own and is not governed here.
 */
final class SkillLock
{
    /** The file's name, in the skills folder. */
    public const FILE = 'skills.lock.json';

    /** @var array<string, array<string, mixed>> */
    private array $entries;

    public function __construct(private readonly string $folder)
    {
        $read          = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : [];
        $this->entries = is_array($read) ? $read : [];
    }

    /** The lock of the application's skills folder, or null without one. */
    public static function forApplication(): ?self
    {
        $folder = Skills::folder();

        return $folder !== null ? new self($folder) : null;
    }

    /** @return array<string, mixed>|null One skill's entry. */
    public function get(string $skill): ?array
    {
        return $this->entries[$skill] ?? null;
    }

    /** @return array<string, array<string, mixed>> Every entry, by skill. */
    public function all(): array
    {
        ksort($this->entries);

        return $this->entries;
    }

    /** Whether a skill may be offered: one with no entry is the application's own; one with an entry, only when turned on. */
    public function allows(string $skill): bool
    {
        return !isset($this->entries[$skill]) || !empty($this->entries[$skill]['enabled']);
    }

    /** @param array<string, mixed> $entry Keep a skill's entry. */
    public function put(string $skill, array $entry): self
    {
        $this->entries[$skill] = $entry;
        ksort($this->entries);
        file_put_contents($this->path(), json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return $this;
    }

    /** Turn a skill on as it is now — its hash is what was read — or off. */
    public function enable(string $skill, bool $on = true): bool
    {
        $entry = $this->get($skill);
        if ($entry === null) {
            return false;
        }
        $entry['enabled']       = $on;
        $entry['approved_hash'] = $on ? self::hash($this->folder . '/' . $skill) : null;
        $this->put($skill, $entry);

        return true;
    }

    /**
     * The hash of a skill folder's files — only those a skill is made of, by path and content — so a
     * change made by hand is seen, and nothing else counts.
     */
    public static function hash(string $folder): string
    {
        $parts = [];
        foreach (self::files($folder) as $relative => $body) {
            $parts[] = $relative . "\0" . hash('sha256', $body);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * A skill folder's files, as a catalogue takes them, by relative path.
     *
     * @return array<string, string>
     */
    public static function files(string $folder): array
    {
        $out = [];
        foreach (array_merge([$folder . '/SKILL.md'], glob($folder . '/references/*') ?: [], glob($folder . '/evals/*.json') ?: []) as $file) {
            $relative = substr($file, strlen($folder) + 1);
            if (is_file($file) && !is_link($file) && preg_match(SkillCatalog::TAKEN, $relative) === 1) {
                $out[$relative] = (string) file_get_contents($file);
            }
        }
        ksort($out);

        return $out;
    }

    private function path(): string
    {
        return rtrim($this->folder, '/') . '/' . self::FILE;
    }
}
