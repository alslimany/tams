<?php

namespace App\Exceptions;

use Exception;

/**
 * The provider answered, but the response carried no bookable fare.
 *
 * This is an expected "cannot price this combination" outcome rather than a
 * system error, so callers fall back to one-way pricing instead of reporting it.
 */
class NoFareAvailableException extends Exception {}
