<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Audience\Explained;

/**
 * Why one person is or is not in a segment, or would or would not come into a rule: each condition
 * in words, with the person's own value — what the screens' "why" shows.
 */
final class AudienceWhyTool extends AudienceTool
{
    public function name(): string
    {
        return 'audience_why';
    }

    public function description(): string
    {
        return 'Why one account is or is not in a segment, or would or would not come into a rule: each condition in words, '
            . 'met or not, with the account\'s own value. Give person and segment, or person and rule.';
    }

    protected function arguments(): array
    {
        return [
            'person'  => ['type' => 'integer', 'description' => 'The account id.'],
            'segment' => ['type' => 'integer', 'description' => 'A segment id.'],
            'rule'    => ['type' => 'integer', 'description' => 'A rule id, instead of a segment.'],
        ];
    }

    protected function tables(): array
    {
        return ['segments'];
    }

    protected function ability(): string
    {
        return 'admin.segments';
    }

    protected function answer(array $input, Window $window): array
    {
        $person = self::id($input, 'person');
        if ($person === 0) {
            throw new \InvalidArgumentException('person is the account id to explain.');
        }
        $rule = self::id($input, 'rule');
        if ($rule > 0) {
            if (!$this->allowed('admin.rules') || !\Pramnos\Audience\Installed::has('rules')) {
                return ['available' => false, 'says' => 'Rules are not open to the administrator asking.'];
            }
            $why = \Pramnos\Audience\Rules\Tester::why($rule, $person);

            return ['wouldComeIn' => $why['enters'], 'reasons' => $why['reasons'], 'conditions' => self::said($why['who'])];
        }
        $segment = \Pramnos\Audience\Segments::get(self::id($input, 'segment'));
        if ($segment === null) {
            return ['found' => false];
        }
        $why = \Pramnos\Audience\Filter::fromArray($segment['filter'])->explain($person);

        return ['matches' => $why['ok'], 'inSegment' => \Pramnos\Audience\Segments::isMember($segment['id'], $person),
            'countedAsAPerson' => $why['inScope'], 'conditions' => self::said($why)];
    }

    /**
     * Each condition in words, groups nested.
     *
     * @param  array<string, mixed> $group
     * @return list<array<string, mixed>>
     */
    private static function said(array $group): array
    {
        $out = [];
        foreach ($group['conditions'] as $c) {
            $out[] = isset($c['conditions'])
                ? ['match' => $c['match'], 'met' => $c['ok'], 'conditions' => self::said($c)]
                : ['met' => $c['ok']] + Explained::condition($c);
        }

        return $out;
    }
}
