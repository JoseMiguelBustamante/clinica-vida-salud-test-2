<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Carbon;

final class ReportDateDefaults
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function apply(array $input): array
    {
        $type = (string) ($input['tipo'] ?? '');
        $today = Carbon::today()->toDateString();
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        if (in_array($type, [ReportService::TYPE_APPOINTMENTS_ON_DATE, ReportService::TYPE_DAILY_AGENDA], true)) {
            if (! self::filled($input['fecha'] ?? null)) {
                $input['fecha'] = $today;
            }
        }

        if (in_array($type, [
            ReportService::TYPE_APPOINTMENTS_BY_PHYSICIAN,
            ReportService::TYPE_APPOINTMENTS_BY_SPECIALTY,
            ReportService::TYPE_APPOINTMENTS_BY_STATUS,
            ReportService::TYPE_PERIOD_TOTALS,
        ], true)) {
            if (! self::filled($input['fecha_inicio'] ?? null)) {
                $input['fecha_inicio'] = $weekStart;
            }
            if (! self::filled($input['fecha_fin'] ?? null)) {
                $input['fecha_fin'] = $today;
            }
        }

        return $input;
    }

    public static function today(): string
    {
        return Carbon::today()->toDateString();
    }

    public static function weekStart(): string
    {
        return Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public static function monthStart(): string
    {
        return Carbon::today()->startOfMonth()->toDateString();
    }

    /**
     * Earliest calendar day used when the user asks for full appointment history.
     */
    public static function historyStart(?string $today = null): string
    {
        $day = $today === null || $today === ''
            ? Carbon::today()
            : Carbon::parse($today)->startOfDay();

        return $day->copy()->subYears(10)->startOfYear()->toDateString();
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
