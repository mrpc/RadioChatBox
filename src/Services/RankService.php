<?php

namespace RadioChatBox\Services;

/**
 * Activity rank derived from a user's public message count. Ranks progress
 * automatically as a user participates more — there is nothing to store; the
 * level is a pure function of the count. Used to show a rank title + colour on
 * the profile card.
 */
class RankService
{
    /**
     * Rank tiers, ascending by threshold. Each: min message count, title, colour.
     *
     * @var array<int, array{min:int, title:string, color:string}>
     */
    private const TIERS = [
        ['min' => 0,    'title' => 'Newcomer', 'color' => '#9ca3af'],
        ['min' => 10,   'title' => 'Regular',  'color' => '#10b981'],
        ['min' => 50,   'title' => 'Active',   'color' => '#3b82f6'],
        ['min' => 200,  'title' => 'Veteran',  'color' => '#8b5cf6'],
        ['min' => 1000, 'title' => 'Legend',   'color' => '#f59e0b'],
    ];

    /**
     * Resolve the rank for a message count.
     *
     * @return array{level:int, title:string, color:string, next_at:int|null, current_min:int}
     */
    public static function forCount(int $count): array
    {
        $count = max(0, $count);
        $level = 0;
        foreach (self::TIERS as $i => $tier) {
            if ($count >= $tier['min']) {
                $level = $i;
            }
        }
        $current = self::TIERS[$level];
        $nextAt = isset(self::TIERS[$level + 1]) ? self::TIERS[$level + 1]['min'] : null;

        return [
            'level'       => $level,
            'title'       => $current['title'],
            'color'       => $current['color'],
            'next_at'     => $nextAt,
            'current_min' => $current['min'],
        ];
    }
}
