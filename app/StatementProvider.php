<?php

namespace App;

enum StatementProvider: string
{
    case Bcp = 'bcp';
    case Interbank = 'interbank';
}
