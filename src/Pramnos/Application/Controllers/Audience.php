<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Filter;
use Pramnos\Audience\Picker;
use Pramnos\Audience\Segments;

/**
 * What the audience builder and picker ({@see \Pramnos\Html\AudienceBuilder}) ask the server:
 * the live count, a breakdown, a sample, and why one person is or is not in.
 *
 * Reachable at `/admin/Audience` as the framework's own controller. A tool other screens open —
 * segments, sends, rules — so no menu item of its own: every action checks the administration
 * floor itself, and every filter is checked against the attributes the account may see.
 *
 * - `catalog` — the attributes, operators, segments, groups and consent lists, as JSON
 * - `preview` (POST `filter`, or `picker`; `breakdown`, `sample`) — the numbers for the panel
 * - `explain` (POST `filter`, `person`) — each condition ✓/✗ with the person's value
 */
class Audience extends Controller
{
    /** A tool, not a screen: see the class comment. */
    protected string $adminAbility = '';

    /** How many people a sample shows at most. */
    public const MAX_SAMPLE = 25;

    /** Registers the actions: all behind sign-in, the questions POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['catalog', 'preview', 'explain']);
        $this->addWriteAction(['preview', 'explain']);
        parent::__construct($application);
    }

    /** Everything the builder offers, for a page that does not embed it. */
    public function catalog(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        return $this->json(self::describe());
    }

    /**
     * The panel's numbers. A filter answers its count, an optional breakdown and a sample; a
     * picker answers what it picked, what the exceptions and consent took out, what is left, and
     * a sample of who it reaches.
     */
    public function preview(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $limit = max(0, min(self::MAX_SAMPLE, (int) ($_POST['sample'] ?? 5)));
        try {
            if (isset($_POST['picker'])) {
                $state = Picker::normalise((string) $_POST['picker']);
                $this->refuseHidden($state['filter']);
                $answer = Picker::counts($state) + ['sample' => $this->people(Picker::final($state)->sample($limit))];
            } else {
                $filter = Filter::fromArray((string) ($_POST['filter'] ?? ''));
                $this->refuseHidden($filter->toArray());
                $answer = ['count' => $filter->count(), 'sample' => $this->people($filter->sample($limit))];
                $key    = (string) ($_POST['breakdown'] ?? '');
                if ($key !== '') {
                    $this->refuseHidden(['conditions' => [['attribute' => $key]]]);
                    $answer['breakdown'] = $filter->breakdown($key);
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($answer);
    }

    /** Why one person is or is not in — the "why" tool of a segment and of a rule. */
    public function explain(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $person = (int) ($_POST['person'] ?? 0);
        if ($person < 1) {
            return $this->json(['error' => t('Which person? Give an account id.')], 422);
        }
        try {
            $filter = Filter::fromArray((string) ($_POST['filter'] ?? ''));
            $this->refuseHidden($filter->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($filter->explain($person));
    }

    /**
     * The catalogue the builder draws from: the attributes the signed-in account may use, the
     * operators' words, and the segments, groups and consent lists to pick from — each list empty
     * where its table is not installed.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $attributes = array_values(array_map(static fn ($a): array => $a->toArray(), Attributes::visible()));
        $segments   = self::quietly(static fn (): array => array_map(
            static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'members' => $s['members']],
            Segments::all()
        ));
        $groups  = self::quietly(static fn (): array => array_map(
            static fn ($id, $name): array => ['id' => $id, 'name' => $name],
            array_keys($names = Attributes::groupNames()),
            $names
        ));
        $consent = self::quietly(static fn (): array => array_map(
            static fn (array $l): array => ['id' => $l['id'], 'name' => $l['name'], 'channel' => $l['channel']],
            ConsentLists::all()
        ));

        return ['attributes' => $attributes, 'operators' => self::operatorWords(), 'segments' => $segments,
            'groups' => $groups, 'consent' => $consent];
    }

    /**
     * The operators in the page's language.
     *
     * @return array<string, string>
     */
    public static function operatorWords(): array
    {
        return [
            'is'                 => t('is'),
            'is_not'             => t('is not'),
            'one_of'             => t('is one of'),
            'not_one_of'         => t('is none of'),
            'contains'           => t('contains'),
            'not_contains'       => t('does not contain'),
            'starts_with'        => t('starts with'),
            'gt'                 => t('is more than'),
            'lt'                 => t('is less than'),
            'between'            => t('is between'),
            'after'              => t('is after'),
            'before'             => t('is before'),
            'more_than_days_ago' => t('more than … days ago'),
            'less_than_days_ago' => t('less than … days ago'),
            'true'               => t('yes'),
            'false'              => t('no'),
            'empty'              => t('is empty'),
            'not_empty'          => t('has a value'),
            'any_of'             => t('has any of'),
            'all_of'             => t('has all of'),
            'none_of'            => t('has none of'),
            'in'                 => t('is in segment'),
            'not_in'             => t('is not in segment'),
        ];
    }

    /**
     * Refuse a filter that asks about an attribute this account may not see — the builder never
     * offers one, so this is a hand-made request.
     *
     * @param array<string, mixed> $tree
     */
    private function refuseHidden(array $tree): void
    {
        $visible = Attributes::visible();
        foreach ($tree['conditions'] ?? [] as $condition) {
            if (isset($condition['conditions'])) {
                $this->refuseHidden($condition);
            } elseif (isset($condition['attribute']) && !isset($visible[$condition['attribute']])) {
                throw new \InvalidArgumentException(t('You may not filter by %s.', (string) $condition['attribute']));
            }
        }
    }

    /**
     * A sample as the panel shows it: a name and the link to the person's card.
     *
     * @param  list<array{userid: int, username: string, firstname: string, lastname: string, email: string}> $rows
     * @return list<array{id: int, name: string, url: string}>
     */
    private function people(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id'   => $row['userid'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']) ?: ($row['username'] ?: $row['email']),
            'url'  => \Pramnos\Html\AudienceBuilder::personUrl($row['userid']),
        ], $rows);
    }

    /**
     * A list, or none where its table is missing.
     *
     * @param  callable(): list<array<string, mixed>> $read
     * @return list<array<string, mixed>>
     */
    private static function quietly(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A JSON answer.
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): \Pramnos\Http\Response
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }
}
