<?php

namespace App\Services\Money;

/**
 * Converts between the pesos people type and the integer centavos we store.
 */
final class Centavos
{
    public static function fromPesos(float|int|string $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }
}
