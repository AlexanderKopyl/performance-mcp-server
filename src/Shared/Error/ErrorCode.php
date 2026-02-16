<?php

declare(strict_types=1);

namespace App\Shared\Error;

enum ErrorCode: string
{
    case INVALID_REQUEST = 'INVALID_REQUEST';
    case METHOD_NOT_FOUND = 'METHOD_NOT_FOUND';
    case NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}
