<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

enum SpendingBoundKind: string
{
    case NativeMonetaryCeiling = 'native_monetary_ceiling';
    case BillableQuantities = 'billable_quantities';
    case Unknown = 'unknown';
}
