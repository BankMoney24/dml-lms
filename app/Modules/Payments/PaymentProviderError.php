<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use RuntimeException;

/** Paystack could not be reached or refused a request. The message is safe to log; it never contains the key. */
final class PaymentProviderError extends RuntimeException
{
}
