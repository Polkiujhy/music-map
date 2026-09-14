<?php

namespace App\Enums;

enum ExportReviewDecision: string
{
    case Keep = 'keep';
    case Remove = 'remove';
}
