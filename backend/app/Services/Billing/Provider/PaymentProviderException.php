<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use RuntimeException;

/** A provider call failed or is unsupported. Its message is never shown to users. */
final class PaymentProviderException extends RuntimeException {}
