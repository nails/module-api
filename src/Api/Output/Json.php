<?php

namespace Nails\Api\Api\Output;

use Nails\Api\Interfaces\Output;
use Nails\Environment;

/**
 * Class Json
 *
 * @package Nails\Api\Output
 */
class Json extends Text
{
    /** @var string */
    const SLUG = 'JSON';

    /** @var string */
    const CONTENT_TYPE = 'application/json';
}
