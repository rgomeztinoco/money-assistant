<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class CategoryOperationBlocked extends Exception implements ShouldntReport {}
