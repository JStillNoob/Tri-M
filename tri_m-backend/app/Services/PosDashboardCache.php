<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PosDashboardCache
{
    private const string REVISION_KEY = 'pos:dashboard:overview:revision';

    public function overview(DashboardAnalytics $analytics, DashboardPeriod $period, ?int $category, bool $refresh): array
    {
        $seconds = (int) config('pos.dashboard_cache_seconds');
        $calculate = fn (): array => $analytics->overview($period, $category);

        if ($seconds <= 0) {
            return $calculate();
        }

        $asOf = $period->current->end->subSecond();
        $key = hash('sha256', json_encode([
            $period->key,
            $category,
            $asOf->tzName,
            $asOf->format('Y-m-d H'),
            Cache::get(self::REVISION_KEY, 'initial'),
        ], JSON_THROW_ON_ERROR));

        $key = 'pos:dashboard:overview:'.$key;
        if ($refresh) {
            $report = $calculate();
            Cache::put($key, $report, $seconds);

            return $report;
        }

        return Cache::remember($key, $seconds, $calculate);
    }

    public static function invalidate(): void
    {
        if ((int) config('pos.dashboard_cache_seconds') > 0) {
            rescue(fn () => Cache::forever(self::REVISION_KEY, (string) Str::uuid()));
        }
    }
}
