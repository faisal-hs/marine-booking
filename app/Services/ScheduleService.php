<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySchedulePeriod;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    public function __construct(protected ScheduleRepositoryInterface $repository) {}

    public function create(Activity $activity, array $data): ActivitySchedulePeriod
    {
        $this->assertNotSameStartAndEnd($data['start_time'], $data['end_time']);

        $data['activity_id'] = $activity->id;
        $data['crosses_midnight'] = ActivitySchedulePeriod::determinesCrossesMidnight(
            $data['start_time'],
            $data['end_time']
        );

        return $this->repository->create($data);
    }

    public function update(ActivitySchedulePeriod $period, array $data): ActivitySchedulePeriod
    {
        $startTime = $data['start_time'] ?? $period->start_time;
        $endTime = $data['end_time'] ?? $period->end_time;

        $this->assertNotSameStartAndEnd($startTime, $endTime);

        $data['crosses_midnight'] = ActivitySchedulePeriod::determinesCrossesMidnight($startTime, $endTime);

        return $this->repository->update($period, $data);
    }

    public function delete(ActivitySchedulePeriod $period): void
    {
        $this->repository->delete($period);
    }

    protected function assertNotSameStartAndEnd(string $startTime, string $endTime): void
    {
        // Normalize to H:i:s for comparison since one side may come in as
        // "17:00" (form input) and the other as "17:00:00" (DB round-trip).
        $normalize = fn(string $t) => strlen($t) === 5 ? $t . ':00' : $t;

        if ($normalize($startTime) === $normalize($endTime)) {
            throw ValidationException::withMessages([
                'end_time' => ['Start and end time cannot be identical. A full-day period is not currently supported.'],
            ]);
        }
    }
}
