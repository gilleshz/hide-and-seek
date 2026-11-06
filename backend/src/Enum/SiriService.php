<?php

declare(strict_types=1);

namespace App\Enum;

enum SiriService: string
{
    case StopMonitoring = 'stop_monitoring';
    case EstimatedTimetable = 'estimated_timetable';
}
