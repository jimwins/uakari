<?php

declare(strict_types=1);

namespace Uakari\Attributes;

use Attribute;

#[Attribute]
class SqlType
{
    public function __construct(
        public string $type
    ) {
    }
}
