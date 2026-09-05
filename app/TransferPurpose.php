<?php

namespace App;

enum TransferPurpose: string
{
    case Savings = 'savings';
    case CardPayment = 'card_payment';
    case Internal = 'internal';
}
