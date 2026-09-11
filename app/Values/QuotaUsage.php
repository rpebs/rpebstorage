<?php

namespace App\Values;

final readonly class QuotaUsage
{
    /**
     * @param  int|null  $total  Null means the provider does not report a total.
     */
    public function __construct(
        public ?int $total,
        public int $used,
    ) {}
}
