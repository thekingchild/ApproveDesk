<?php

namespace App\Enums;

enum IntegrationDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case DeadLetter = 'dead_letter';
}
