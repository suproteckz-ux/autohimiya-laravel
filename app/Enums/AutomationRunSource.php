<?php

namespace App\Enums;

enum AutomationRunSource: string
{
    case Scheduler = 'scheduler';
    case Admin = 'admin';
    case Manual = 'manual';
    case System = 'system';
    case Deployment = 'deployment';
}