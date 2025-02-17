<?php

declare(strict_types=1);

namespace Uakari\Attributes;

use Attribute;
use Uakari\Enums\DefaultConstant;

#[Attribute]
class SqlDefault
{
    public function __construct(
        public string|DefaultConstant $default
    ) {
    }
}
