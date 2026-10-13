<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Who is on the site: sessions active in the last quarter hour, people and guests, and the
 * last day hour by hour. No addresses, browsers or pages.
 */
final class SessionsTool extends AdminTool
{
    /** A session seen within this many seconds is somebody on the site now. */
    public const ONLINE = 900;

    public function name(): string
    {
        return 'sessions';
    }

    public function description(): string
    {
        return 'Who is on the site: sessions active in the last 15 minutes, signed-in people and '
            . 'guests, and sessions last active in each hour of the last 24. Counts only.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has('#PREFIX#sessions')) {
            return self::absent('sessions');
        }

        $now    = time();
        $online = fn () => $this->table('#PREFIX#sessions')->where('time', '>=', $now - self::ONLINE);
        $hours  = [];
        for ($h = 23; $h >= 0; $h--) {
            $hours[gmdate('Y-m-d H:00', $now - $h * 3600)] = 0;
        }

        $result = $this->table('#PREFIX#sessions')->select(['time'])->where('time', '>=', $now - 86400)->get();
        while (($row = $result->fetch()) !== null) {
            $label = gmdate('Y-m-d H:00', (int) $row['time']);
            if (isset($hours[$label])) {
                $hours[$label]++;
            }
        }

        return [
            'online_now'    => $online()->count(),
            'people_online' => $online()->where('guest', 0)->count(),
            'guests_online' => $online()->where('guest', 1)->count(),
            'last_24_hours' => $hours,
        ];
    }
}
