<?php

namespace App\Enums;

enum TransactionSource: string
{
    case System = 'system';
    case Admin = 'admin';
    case Webhook = 'webhook';
    case Api = 'api';
    case Cron = 'cron';
}