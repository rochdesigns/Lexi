<?php

namespace App\Http\Controllers;

use App\Services\MonthlyForecastService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ForecastController extends Controller
{
    public function __construct(private MonthlyForecastService $forecast) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Forecast', $this->forecast->build($request->user()));
    }
}
