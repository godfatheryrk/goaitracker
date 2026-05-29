<?php

namespace App\Enums;

enum StepSource: string
{
    case AiInitial = 'ai_initial';
    case AiExtension = 'ai_extension';
    case Manual = 'manual';
}
