<?php

namespace App\Http\Controllers;

use App\Actions\Home\ReadHome;
use App\Http\Requests\IndexHomeRequest;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(private ReadHome $readHome) {}

    public function __invoke(IndexHomeRequest $request): Response
    {
        return Inertia::render('home', $this->readHome->handle(
            owner: $request->user(),
            filters: $request->validated(),
        ));
    }
}
