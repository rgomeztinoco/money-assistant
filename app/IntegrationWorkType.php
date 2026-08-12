<?php

namespace App;

enum IntegrationWorkType: string
{
    case GmailSynchronization = 'gmail_synchronization';
    case GmailMessage = 'gmail_message';
    case ReminderDelivery = 'reminder_delivery';
}
