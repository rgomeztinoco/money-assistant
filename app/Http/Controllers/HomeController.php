<?php

namespace App\Http\Controllers;

use App\Actions\Home\ReadHome;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(private ReadHome $readHome) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('home', $this->readHome->handle($request->user()));
    }
}
