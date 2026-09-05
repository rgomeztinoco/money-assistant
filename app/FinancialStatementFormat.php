<?php

namespace App;

enum FinancialStatementFormat: string
{
    case Bcp = 'bcp';
    case Interbank = 'interbank';
}
