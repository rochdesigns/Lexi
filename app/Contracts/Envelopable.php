<?php

namespace App\Contracts;

interface Envelopable
{
    public function envelopeName(): string;

    public function envelopeIcon(): string;

    public function envelopeColor(): string;

    public function defaultAssignedAmount(): float;
}
