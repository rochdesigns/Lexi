<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CashflowController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Cashflow');
    }
}
