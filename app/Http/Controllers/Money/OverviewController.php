<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Money/Overview');
    }
}
