<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpFoundation\Response;

final class OpenClawConfirmationRejected extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $httpStatus = Response::HTTP_CONFLICT,
    ) {
        parent::__construct('The Confirmation Grant is not valid for this operation.');
    }
}
