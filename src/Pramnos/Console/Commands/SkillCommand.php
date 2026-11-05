<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\SkillCatalog;
use Pramnos\Llm\Chat\SkillLock;
use Pramnos\Llm\Chat\Skills;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the `skill:*` commands share: the skills folder (`llm.chat.skills_path`), its lock, the
 * catalogue, and writing a skill's files — only the files a skill is made of, never a script.
 */
abstract class SkillCommand extends Command
{
    /** The catalogue the commands read; a test gives one that answers without the network. */
    public static ?SkillCatalog $catalog = null;

    /** The skills folder, or null — said — when app.php names none. */
    protected function folder(OutputInterface $output): ?string
    {
        $folder = Skills::folder();
        if ($folder === null) {
            $output->writeln("<error>No skills folder: set 'llm' => ['chat' => ['skills_path' => 'app/skills']] in app/app.php.</error>");

            return null;
        }
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        return rtrim($folder, '/');
    }

    protected function catalog(): SkillCatalog
    {
        return self::$catalog ?? new SkillCatalog();
    }

    protected function lock(string $folder): SkillLock
    {
        return new SkillLock($folder);
    }

    /**
     * Put a skill's files in a folder, those of an earlier version that are not among them removed.
     *
     * @param array<string, string> $files By path relative to the folder, as SkillCatalog::fetch() gives them
     */
    protected function write(string $dest, array $files): void
    {
        foreach (array_keys(SkillLock::files($dest)) as $old) {
            if (!isset($files[$old])) {
                unlink($dest . '/' . $old);
            }
        }
        foreach ($files as $relative => $body) {
            // SkillCatalog::TAKEN admits no `..` and no other folder; asked again, as this writes.
            if (preg_match(SkillCatalog::TAKEN, $relative) !== 1) {
                continue;
            }
            if (!is_dir(dirname($dest . '/' . $relative))) {
                mkdir(dirname($dest . '/' . $relative), 0775, true);
            }
            file_put_contents($dest . '/' . $relative, $body);
        }
    }

    /** A source's slug: the last part of its path, or what `--as` says. */
    protected function slug(string $path, ?string $as): string
    {
        $slug = $as !== null && $as !== '' ? $as : basename($path);
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $slug) !== 1) {
            throw new \InvalidArgumentException('"' . $slug . '" is not a name a skill can have; give one with --as.');
        }

        return $slug;
    }
}
