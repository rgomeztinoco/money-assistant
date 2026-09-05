<?php

namespace App;

enum IncomeSource: string
{
    case Salary = 'salary';
    case IndependentWork = 'independent_work';
    case Investments = 'investments';
    case Other = 'other';
}
