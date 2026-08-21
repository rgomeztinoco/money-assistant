<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\ReadDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private ReadDashboard $readDashboard) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', $this->readDashboard->handle($request->user()));
    }
}
