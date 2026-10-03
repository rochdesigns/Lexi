<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Money/Plan');
    }
}
