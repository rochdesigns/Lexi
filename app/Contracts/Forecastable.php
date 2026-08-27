<?php

namespace App\Contracts;

use App\Support\CyclePeriod;

interface Forecastable
{
    public function forecastAmount(CyclePeriod $period, int $periodIndex): float;
}
