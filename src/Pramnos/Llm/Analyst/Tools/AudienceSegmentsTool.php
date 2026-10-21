<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Audience\Segments;

/**
 * The segments: how many are in each, who came and went over seven days, and what uses each; or
 * one segment with its filter. Counts only — never a person.
 */
final class AudienceSegmentsTool extends AudienceTool
{
    public function name(): string
    {
        return 'audience_segments';
    }

    public function description(): string
    {
        return 'Segments (named audiences kept up to date): members now, entered and left over 7 days, '
            . 'what uses each. Give segment for one with its filter. Counts only.';
    }

    protected function arguments(): array
    {
        return ['segment' => ['type' => 'integer', 'description' => 'One segment by id, with its filter.']];
    }

    protected function tables(): array
    {
        return ['segments', 'segment_members'];
    }

    protected function ability(): string
    {
        return 'admin.segments';
    }

    protected function answer(array $input, Window $window): array
    {
        $describe = static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'description' => $s['description'],
            'members' => $s['members'], 'last7days' => Segments::movement($s['id']), 'usedBy' => Segments::usedBy($s['id']),
            'workedOut' => $s['recomputedAt'] === null ? null : date('Y-m-d H:i', $s['recomputedAt'])];
        $id = self::id($input, 'segment');
        if ($id > 0) {
            $segment = Segments::get($id);

            return $segment === null ? ['found' => false] : $describe($segment) + ['filter' => $segment['filter']];
        }

        return ['segments' => array_map($describe, Segments::all())];
    }
}
