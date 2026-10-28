<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * What changed in the application's code, and in the framework under it, over a window — so a
 * question like "this worked yesterday and not today, why?" can be checked against a change rather
 * than guessed at.
 *
 * Two sources, each read only where it is there:
 *
 * - **the application's git history** — the commits in the window (or whose message holds a word),
 *   newest first, each with its date, its message and the files it touched; read with `git log` in
 *   the application's root, so a server deployed without `.git` simply has none;
 * - **the framework's changelog** — the day posts it ships in `docs/version-history/posts/`, whose
 *   section titles say what changed on each day, inside `vendor/` (or the framework's own checkout).
 *
 * The answer is for a person, not a developer: the description tells the model to say what changed
 * for them in plain words, never commit hashes or file names. Nothing here writes; personal data in a
 * message is masked like every other tool result.
 */
final class ChangesTool extends AdminTool
{
    /** Commits listed at most. */
    public const COMMITS = 40;

    /** Files listed per commit at most. */
    public const FILES = 12;

    public function __construct(private readonly ?string $root = null)
    {
    }

    public function name(): string
    {
        return 'recent_changes';
    }

    public function description(): string
    {
        return 'What changed in this application\'s code — and in the framework it runs on — over a window, newest first: '
            . 'each change\'s date, what it says it did, and what it touched. Use it when somebody asks why something works '
            . 'differently from before ("yesterday I could…, today I can\'t"), to see whether a change explains it. '
            . 'Answer them in plain, non-technical words — what changed for them and when, and whether it is likely the reason — '
            . 'never commit hashes, file names, code or jargon. If nothing in the window explains it, say so.';
    }

    protected function arguments(): array
    {
        return ['about' => ['type' => 'string', 'description' => 'A word the change\'s description should hold, e.g. "invoice" or "login". Optional.']];
    }

    protected function answer(array $input, Window $window): array
    {
        $about = trim((string) ($input['about'] ?? ''));

        return [
            'window'      => $window->describe(),
            'application' => $this->commits($window, $about),
            'framework'   => $this->frameworkDays($window, $about),
        ];
    }

    /**
     * The application's commits in the window, or why there are none to read.
     *
     * @return array{available: bool, says?: string, changes?: list<array{date: string, said: string, touched: list<string>}>}
     */
    private function commits(Window $window, string $about): array
    {
        $root = $this->root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd());
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            return ['available' => false, 'says' => 'This installation keeps no history of its code (it was not deployed with git).'];
        }
        $args = ['git', '-C', $root, 'log', '--no-merges', '--date=iso-strict', '--name-only',
            '--pretty=format:%x1e%ad%x1f%s%x1f%b', '-n', (string) self::COMMITS,
            '--since=@' . $window->from, '--until=@' . $window->to];
        if ($about !== '') {
            array_push($args, '-i', '--fixed-strings', '--grep=' . $about);
        }
        $out = self::run($args);
        if ($out === null) {
            return ['available' => false, 'says' => 'The history of the code could not be read here.'];
        }
        $changes = [];
        foreach (array_filter(explode("\x1e", $out), static fn (string $c): bool => trim($c) !== '') as $chunk) {
            [$date, $subject, $rest] = array_pad(explode("\x1f", $chunk, 3), 3, '');
            $lines = preg_split('/\R/', (string) $rest) ?: [];
            // %b's body comes first, then the file names --name-only adds after a blank line.
            $files = array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== '' && preg_match('/^[\w.\-\/]+$/', $l) === 1));
            $body  = trim(implode("\n", array_diff(array_map('trim', $lines), $files)));
            $changes[] = [
                'date'    => substr($date, 0, 16),
                'said'    => mb_substr(trim($subject . ($body !== '' ? "\n" . $body : '')), 0, 800),
                'touched' => array_slice($files, 0, self::FILES),
            ];
        }

        return ['available' => true, 'changes' => $changes];
    }

    /**
     * What the framework's changelog says for each day in the window: the titles of its entries.
     *
     * @return list<array{date: string, changes: list<string>}>
     */
    private function frameworkDays(Window $window, string $about): array
    {
        $root = defined('ROOT') ? (string) ROOT : (string) getcwd();
        $dirs = [$root . '/vendor/mrpc/pramnosframework/docs/version-history/posts', dirname(__DIR__, 5) . '/docs/version-history/posts'];
        $dir  = null;
        foreach ($dirs as $candidate) {
            if (is_dir($candidate)) {
                $dir = $candidate;
                break;
            }
        }
        if ($dir === null) {
            return [];
        }
        $days = [];
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\.md$/', $file, $m) !== 1) {
                continue;
            }
            $at = strtotime($m[1] . ' 12:00:00 UTC');
            if ($at === false || $at < $window->from - 43200 || $at > $window->to + 43200) {
                continue;
            }
            preg_match_all('/^## (.+)$/m', (string) file_get_contents($file), $titles);
            $titles = array_values(array_filter($titles[1], static fn (string $t): bool => $about === '' || stripos($t, $about) !== false));
            if ($titles !== []) {
                $days[] = ['date' => $m[1], 'changes' => $titles];
            }
        }
        usort($days, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $days;
    }

    /**
     * Run a command — arguments, never a shell — and answer what it printed, or null when it failed.
     *
     * @param list<string> $args
     */
    private static function run(array $args): ?string
    {
        $process = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $out : null;
    }
}
