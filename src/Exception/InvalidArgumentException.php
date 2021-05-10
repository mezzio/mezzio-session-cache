<?php

namespace Mezzio\Session\Cache\Exception;

use InvalidArgumentException as PhpInvalidArgumentException;

class InvalidArgumentException extends PhpInvalidArgumentException implements ExceptionInterface
{
}
