<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;

/**
 * A/B tests, defined in the administration and asked about by the site's code.
 *
 * ```php
 * // the site
 * if (Experiments::variant('premium-annual-first') === 'B') {
 *     // the annual package first
 * }
 *
 * // what a visitor was shown carries over to their account by itself, the first time they are
 * // asked about signed in; an application signing somebody up may also say so at once:
 * Experiments::adopt(Experiments::visitor(), $newUserId);
 *
 * // the administration
 * $id = Experiments::create('premium-annual-first', 'Annual first on the Premium page',
 *     [['key' => 'A', 'share' => 50, 'label' => 'As today'], ['key' => 'B', 'share' => 50, 'label' => 'Annual first']],
 *     goalEvent: 'payment.made', goalDays: 7, segment: $parentsWithoutSubscription);
 * Experiments::start($id);
 * Experiments::results($id);      // per variant: people, converted, rate, confidence, still needed
 * Experiments::decide($id, 'B');  // everybody gets B from now on
 * ```
 *
 * Each person — or, before signing up, each visitor, by a `pf_visitor` cookie — gets one variant
 * for good, by share. Many tests run at once, each on its own. A test limited to a segment gives
 * people outside it, and visitors, no variant (`null`: show what is there today). A test not
 * running answers its winner once there is one, and nothing before. A filter can ask "in test X,
 * variant B", and a rule's split can follow a test. Before the tables exist, `variant()` is null.
 */
final class Experiments
{
    /** The cookie a visitor is known by. */
    public const COOKIE = 'pf_visitor';

    /** @var array<string, array<string, mixed>|null> This request's tests, by slug */
    private static array $known = [];

    /**
     * Make a test, as a draft.
     *
     * @param  list<array{key: string, share: int, label?: string}> $variants
     * @throws \InvalidArgumentException
     */
    public static function create(string $slug, string $name, array $variants, ?string $goalEvent = null, int $goalDays = 7, ?int $segment = null, ?int $owner = null): int
    {
        self::db()->queryBuilder()->table('#PREFIX#experiments')->insert(self::checked($slug, $name, $variants, $goalDays) + [
            'status' => 'draft', 'segment' => $segment, 'goalevent' => $goalEvent === '' ? null : $goalEvent,
            'owner' => $owner, 'createdat' => time(),
        ]);
        $id = (int) self::db()->getInsertId();
        \Pramnos\Auth\AuditLog::change('experiment.created', $owner, 'experiment', $id, [], ['slug' => $slug, 'variants' => $variants]);

        return $id;
    }

    /**
     * Change a test that has not started: its name, variants, segment, goal.
     *
     * @param array{name?: string, variants?: list<array<string, mixed>>, segment?: ?int, goalEvent?: ?string, goalDays?: int} $changes
     */
    public static function update(int $id, array $changes, ?int $by = null): void
    {
        $test = self::get($id) ?? throw new \InvalidArgumentException("No test {$id}.");
        if ($test['status'] !== 'draft') {
            throw new \InvalidArgumentException(t('A test that has started cannot be changed: its numbers would mean nothing.'));
        }
        $row = self::checked($test['slug'], $changes['name'] ?? $test['name'], $changes['variants'] ?? $test['variants'], (int) ($changes['goalDays'] ?? $test['goalDays']));
        unset($row['slug']);
        $row += ['segment' => array_key_exists('segment', $changes) ? $changes['segment'] : $test['segment'],
            'goalevent' => array_key_exists('goalEvent', $changes) ? ($changes['goalEvent'] ?: null) : $test['goalEvent']];
        self::db()->queryBuilder()->table('#PREFIX#experiments')->where('experimentid', $id)->update($row);
        \Pramnos\Auth\AuditLog::change('experiment.updated', $by, 'experiment', $id, $test, self::get($id) ?? []);
        self::$known = [];
    }

    /** Start a test: from now on people are given variants. */
    public static function start(int $id, ?int $by = null): void
    {
        self::move($id, 'running', ['startedat' => time()], $by);
    }

    /** Stop a test without a winner: from now on nobody is given a variant. */
    public static function stop(int $id, ?int $by = null): void
    {
        self::move($id, 'stopped', ['endedat' => time()], $by);
    }

    /** Make a variant the winner: the test stops and everybody gets it. */
    public static function decide(int $id, string $variant, ?int $by = null): void
    {
        $test = self::get($id) ?? throw new \InvalidArgumentException("No test {$id}.");
        if (!in_array($variant, array_column($test['variants'], 'key'), true)) {
            throw new \InvalidArgumentException(t('The test has no variant %s.', $variant));
        }
        self::move($id, 'stopped', ['endedat' => $test['endedAt'] ?? time(), 'winner' => $variant], $by);
    }

    /**
     * The variant this person or visitor sees in a test — given now if they have none yet — or
     * null: not running and undecided, outside its segment, or not installed.
     */
    public static function variant(string $slug, ?int $person = null, ?string $visitor = null): ?string
    {
        if (!Installed::has('experiments', 'pramnos.experiment_assignments')) {
            return null;
        }
        $test = self::bySlug($slug);
        if ($test === null) {
            return null;
        }
        if ($test['status'] !== 'running') {
            return $test['winner'];
        }
        if ($person === null && $visitor === null) {
            $user    = \Pramnos\User\User::current();
            $person  = $user ? (int) $user->userid : null;
            $visitor = $person === null ? self::visitor() : null;
            if ($person !== null) {
                self::adoptOnce($person);
            }
        }
        if ($test['segment'] !== null && ($person === null || !Segments::isMember($test['segment'], $person))) {
            return null;
        }

        return self::assign($test, $person !== null ? 'p:' . $person : 'v:' . $visitor, $person);
    }

    /**
     * This browser's visitor id: the cookie's, or a new one set on the response.
     */
    public static function visitor(): string
    {
        $id = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            $id = bin2hex(random_bytes(16));
            $_COOKIE[self::COOKIE] = $id;
            if (!headers_sent()) {
                setcookie(self::COOKIE, $id, ['expires' => time() + 400 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true,
                    'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')]);
            }
        }

        return $id;
    }

    /**
     * A visitor signed up or signed in: what they were shown becomes the person's — unless the
     * person already has a variant in that test, which stands.
     *
     * @return int How many assignments carried over
     */
    public static function adopt(string $visitor, int $person): int
    {
        if ($visitor === '' || $person < 1 || !Installed::has('experiments', 'pramnos.experiment_assignments')) {
            return 0;
        }
        $moved = 0;
        $table = static fn () => self::db()->queryBuilder()->table('pramnos.experiment_assignments');
        foreach ($table()->where('subject', 'v:' . $visitor)->getAllOrFail() as $row) {
            $held = $table()->where('experimentid', (int) $row['experimentid'])->where('subject', 'p:' . $person)->limit(1)->getAllOrFail();
            if ($held !== []) {
                $table()->where('id', (int) $row['id'])->delete();
                continue;
            }
            $table()->where('id', (int) $row['id'])->update(['subject' => 'p:' . $person, 'personid' => $person]);
            $moved++;
        }

        return $moved;
    }

    /**
     * The first time a signed-in person is asked about who still carries a visitor cookie, what the
     * visitor was shown becomes theirs — once a session, so signing up needs no call of its own.
     */
    private static function adoptOnce(int $person): void
    {
        $visitor = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($visitor === '' || ($_SESSION['pf_visitor_adopted'] ?? '') === $visitor . ':' . $person) {
            return;
        }
        self::adopt($visitor, $person);
        $_SESSION['pf_visitor_adopted'] = $visitor . ':' . $person;
    }

    /**
     * How each variant is doing: people given it, how many reached the goal within its days,
     * the rate, how sure the difference from the first variant is, and how many more people each
     * variant needs before it can be told.
     *
     * @return array{variants: list<array<string, mixed>>, visitors: int}
     */
    public static function results(int $id): array
    {
        $test = self::get($id) ?? throw new \InvalidArgumentException("No test {$id}.");
        $rows = [];
        foreach ($test['variants'] as $variant) {
            $people = (int) (self::db()->queryBuilder()->table('pramnos.experiment_assignments')
                ->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])->where('experimentid', $id)
                ->where('variant', $variant['key'])->whereNotNull('personid')->getAllOrFail()[0]['n'] ?? 0);
            $converted = $test['goalEvent'] === null || !Installed::eventLog() ? 0 : (int) (self::db()->queryBuilder()
                ->table('pramnos.experiment_assignments AS experiment_assignments')
                ->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])
                ->where('experiment_assignments.experimentid', $id)->where('experiment_assignments.variant', $variant['key'])
                ->whereNotNull('experiment_assignments.personid')
                ->whereExists(static function ($sub) use ($test): void {
                    PersonEvents::occurrences($sub, $test['goalEvent'], 'experiment_assignments.personid')->select([new \Pramnos\Database\Expression('1')])
                        ->whereRaw('person_log.created_at >= ' . PersonEvents::moment('experiment_assignments.assignedat'))
                        ->whereRaw('person_log.created_at <= ' . PersonEvents::moment('experiment_assignments.assignedat', $test['goalDays'] * 86400));
                })->getAllOrFail()[0]['n'] ?? 0);
            $rows[] = ['key' => $variant['key'], 'label' => $variant['label'], 'share' => $variant['share'],
                'people' => $people, 'converted' => $converted, 'rate' => $people > 0 ? $converted / $people : null];
        }
        $control = $rows[0];
        foreach ($rows as $i => $row) {
            $rows[$i] += $i === 0 ? ['confidence' => null, 'needed' => null] : self::compare($control, $row);
        }
        $visitors = (int) (self::db()->queryBuilder()->table('pramnos.experiment_assignments')
            ->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])->where('experimentid', $id)->whereNull('personid')->getAllOrFail()[0]['n'] ?? 0);

        return ['variants' => $rows, 'visitors' => $visitors, 'counted' => $test['goalEvent'] !== null && Installed::eventLog()];
    }

    /**
     * One test, or null.
     *
     * @return array{id: int, slug: string, name: string, status: string, segment: ?int, variants: list<array{key: string, share: int, label: string}>, goalEvent: ?string, goalDays: int, winner: ?string, startedAt: ?int, endedAt: ?int, createdAt: int}|null
     */
    public static function get(int $id): ?array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#experiments')->where('experimentid', $id)->limit(1)->getAllOrFail();

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * Every test, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(self::row(...), self::db()->queryBuilder()->table('#PREFIX#experiments')->orderBy('experimentid', 'desc')->getAllOrFail());
    }

    /** Forget this request's tests: tests, and a change in the same request. */
    public static function reset(): void
    {
        self::$known = [];
    }

    /**
     * A test by its slug, read once a request.
     *
     * @return array<string, mixed>|null
     */
    public static function bySlug(string $slug): ?array
    {
        if (!array_key_exists($slug, self::$known)) {
            $rows = self::db()->queryBuilder()->table('#PREFIX#experiments')->where('slug', $slug)->limit(1)->getAllOrFail();
            self::$known[$slug] = $rows === [] ? null : self::row($rows[0]);
        }

        return self::$known[$slug];
    }

    /**
     * The subject's variant: kept if given before, else given now by share, the same for the same
     * subject whoever asks first.
     *
     * @param array<string, mixed> $test
     */
    private static function assign(array $test, string $subject, ?int $person): string
    {
        $table = static fn () => self::db()->queryBuilder()->table('pramnos.experiment_assignments');
        $held  = $table()->where('experimentid', $test['id'])->where('subject', $subject)->limit(1)->getAllOrFail();
        if ($held !== []) {
            return (string) $held[0]['variant'];
        }
        $bucket = crc32($test['slug'] . ':' . $subject) % 100;
        $chosen = end($test['variants'])['key'];
        $edge   = 0;
        foreach ($test['variants'] as $variant) {
            $edge += $variant['share'];
            if ($bucket < $edge) {
                $chosen = $variant['key'];
                break;
            }
        }
        // Two requests at once would choose the same, by the same hash; the second insert is ignored.
        $table()->insertOrIgnore(['experimentid' => $test['id'], 'subject' => $subject, 'personid' => $person, 'variant' => $chosen, 'assignedat' => time()]);

        return $chosen;
    }

    /**
     * How sure a variant's difference from the control is — a two-proportion z-test — and how
     * many more people each needs for that difference to be told at 95% with 80% power.
     *
     * @param  array{people: int, converted: int, rate: ?float} $control
     * @param  array{people: int, converted: int, rate: ?float} $variant
     * @return array{confidence: ?float, needed: ?int}
     */
    public static function compare(array $control, array $variant): array
    {
        $n1 = $control['people'];
        $n2 = $variant['people'];
        if ($n1 === 0 || $n2 === 0) {
            return ['confidence' => null, 'needed' => null];
        }
        $p1 = $control['converted'] / $n1;
        $p2 = $variant['converted'] / $n2;
        $pooled = ($control['converted'] + $variant['converted']) / ($n1 + $n2);
        $se     = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));
        $confidence = $se == 0.0 ? null : 1 - 2 * (1 - self::normal(abs($p2 - $p1) / $se));
        if ($p1 == $p2) {
            return ['confidence' => $confidence, 'needed' => null];
        }
        $each = (1.959964 + 0.841621) ** 2 * ($p1 * (1 - $p1) + $p2 * (1 - $p2)) / (($p2 - $p1) ** 2);

        return ['confidence' => $confidence, 'needed' => max(0, (int) ceil($each) - min($n1, $n2))];
    }

    /** The standard normal distribution's cumulative probability (Abramowitz and Stegun 26.2.17). */
    private static function normal(float $z): float
    {
        $t = 1 / (1 + 0.2316419 * $z);
        $d = 0.3989422804014327 * exp(-$z * $z / 2);

        return 1 - $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
    }

    /**
     * @param  list<array<string, mixed>> $variants
     * @return array<string, mixed>
     */
    private static function checked(string $slug, string $name, array $variants, int $goalDays): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $slug) !== 1) {
            throw new \InvalidArgumentException(t('A test is named for the code in lower-case letters, digits, . _ -.'));
        }
        $name = mb_substr(trim(strip_tags($name)), 0, 150);
        if ($name === '') {
            throw new \InvalidArgumentException(t('A test needs a name.'));
        }
        $clean = [];
        foreach (array_values($variants) as $variant) {
            $key   = trim((string) ($variant['key'] ?? ''));
            $share = (int) ($variant['share'] ?? 0);
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) !== 1 || $share < 1 || isset($clean[$key])) {
                throw new \InvalidArgumentException(t('Each variant has its own key and a share of at least 1.'));
            }
            $clean[$key] = ['key' => $key, 'share' => $share, 'label' => trim((string) ($variant['label'] ?? $key)) ?: $key];
        }
        if (count($clean) < 2 || array_sum(array_column($clean, 'share')) !== 100) {
            throw new \InvalidArgumentException(t('A test has two variants or more, their shares adding up to 100.'));
        }
        if ($goalDays < 1) {
            throw new \InvalidArgumentException(t('The goal is counted over a day or more.'));
        }

        return ['slug' => $slug, 'name' => $name, 'variants' => json_encode(array_values($clean), JSON_UNESCAPED_UNICODE), 'goaldays' => $goalDays];
    }

    /** @param array<string, mixed> $values */
    private static function move(int $id, string $status, array $values, ?int $by): void
    {
        $test = self::get($id) ?? throw new \InvalidArgumentException("No test {$id}.");
        self::db()->queryBuilder()->table('#PREFIX#experiments')->where('experimentid', $id)->update(['status' => $status] + $values);
        \Pramnos\Auth\AuditLog::change('experiment.' . (isset($values['winner']) ? 'decided' : $status), $by, 'experiment', $id,
            ['status' => $test['status'], 'winner' => $test['winner']], ['status' => $status, 'winner' => $values['winner'] ?? $test['winner']]);
        self::$known = [];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{id: int, slug: string, name: string, status: string, segment: ?int, variants: list<array{key: string, share: int, label: string}>, goalEvent: ?string, goalDays: int, winner: ?string, startedAt: ?int, endedAt: ?int, createdAt: int}
     */
    private static function row(array $row): array
    {
        return [
            'id'        => (int) $row['experimentid'],
            'slug'      => (string) $row['slug'],
            'name'      => (string) $row['name'],
            'status'    => (string) $row['status'],
            'segment'   => $row['segment'] === null ? null : (int) $row['segment'],
            'variants'  => (array) json_decode((string) $row['variants'], true),
            'goalEvent' => $row['goalevent'] === null ? null : (string) $row['goalevent'],
            'goalDays'  => (int) $row['goaldays'],
            'winner'    => $row['winner'] === null ? null : (string) $row['winner'],
            'startedAt' => $row['startedat'] === null ? null : (int) $row['startedat'],
            'endedAt'   => $row['endedat'] === null ? null : (int) $row['endedat'],
            'createdAt' => (int) $row['createdat'],
        ];
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
