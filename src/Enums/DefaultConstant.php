<?php

declare(strict_types=1);

namespace Uakari\Enums;

enum DefaultConstant: string
{
    case CurrentTimestamp = "datetime('now')";
}
