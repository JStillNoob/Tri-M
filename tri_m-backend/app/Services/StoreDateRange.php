<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreDateRange
{
    private function __construct(public readonly CarbonImmutable $start, public readonly CarbonImmutable $end) {}

    public static function between(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start, $end);
    }

    public static function fromRequest(Request $request): self
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:today,7d,30d,custom'],
            'from' => ['required_with:to', 'required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $timezone = config('pos.timezone');
        $today = CarbonImmutable::today($timezone);
        $period = $filters['period'] ?? 'today';
        $days = ['today' => 0, '7d' => 6, '30d' => 29, 'custom' => 0];
        $start = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone) : $today->subDays($days[$period]);
        $end = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->addDay() : $today->addDay();

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'The date range may contain at most 366 days.']);
        }

        return new self($start, $end);
    }

    public static function today(): self
    {
        $today = CarbonImmutable::today(config('pos.timezone'));

        return new self($today, $today->addDay());
    }

    public function apply($query, string $column)
    {
        return $query
            ->where($column, '>=', $this->start->utc()->format('Y-m-d H:i:s'))
            ->where($column, '<', $this->end->utc()->format('Y-m-d H:i:s'));
    }
}
