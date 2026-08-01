<?php

namespace App;

enum IntegrationWorkType: string
{
    case GmailSynchronization = 'gmail_synchronization';
    case GmailMessage = 'gmail_message';
    case AiClassification = 'ai_classification';
    case DailyExchangeRateSeed = 'daily_exchange_rate_seed';
    case ReminderDelivery = 'reminder_delivery';
}
