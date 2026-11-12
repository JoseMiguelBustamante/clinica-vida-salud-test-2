<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use RuntimeException;

final class PaymentGatewayUnavailable extends RuntimeException
{
}
