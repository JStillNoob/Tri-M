<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\WeekDay;

class DashboardPeriod
{
    private function __construct(
        public readonly string $key,
        public readonly StoreDateRange $calendar,
        public readonly StoreDateRange $current,
        public readonly StoreDateRange $previous,
    ) {}

    public static function current(string $key): self
    {
        $now = CarbonImmutable::now(config('pos.timezone'));
        $today = $now->startOfDay();
        [$start, $end, $previousStart] = match ($key) {
            'today' => [$today, $today->addDay(), $today->subDay()],
            'week' => [
                $today->startOfWeek(WeekDay::Monday),
                $today->startOfWeek(WeekDay::Monday)->addWeek(),
                $today->startOfWeek(WeekDay::Monday)->subWeek(),
            ],
            'month' => [$today->startOfMonth(), $today->startOfMonth()->addMonth(), $today->startOfMonth()->subMonth()],
        };
        $elapsedDays = (int) $start->diffInDays($today);
        $previousCutoff = $previousStart->addDays($elapsedDays)->setTimeFrom($now)->startOfSecond()->addSecond();
        $currentCutoff = $now->startOfSecond()->addSecond();

        return new self(
            $key,
            StoreDateRange::between($start, $end),
            StoreDateRange::between($start, $currentCutoff->min($end)),
            StoreDateRange::between($previousStart, $previousCutoff->min($start)),
        );
    }

    public function calendarDays(): int
    {
        return (int) $this->calendar->start->diffInDays($this->calendar->end);
    }
}
