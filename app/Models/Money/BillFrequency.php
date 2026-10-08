<?php

namespace App\Models\Money;

enum BillFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    /** Months between one due date and the next. */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual => 12,
        };
    }

    /** Whether the bill is paid in a lump on a due month rather than every month. */
    public function isSetAside(): bool
    {
        return $this !== self::Monthly;
    }

    /** Whether a bill with this frequency falls due in $month (1 to 12), counting from its first due month. */
    public function fallsDueIn(?int $dueMonth, int $month): bool
    {
        if ($this === self::Monthly || $dueMonth === null) {
            return $this === self::Monthly;
        }

        return (($month - $dueMonth) % $this->months() + $this->months()) % $this->months() === 0;
    }
}
