<?php

namespace Nails\Api\Api\Output;

use Nails\Api\Interfaces\Output;
use Nails\Environment;

/**
 * Class Text
 *
 * @package Nails\Api\Output
 */
class Text implements Output
{
    /** @var string */
    const SLUG = 'TEXT';

    /** @var string */
    const CONTENT_TYPE = 'text/html';

    // --------------------------------------------------------------------------

    /**
     * Returns the slug which will trigger the output format
     *
     * @return string
     */
    public static function getSlug(): string
    {
        return static::SLUG;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the content type for the output format
     *
     * @return string
     */
    public static function getContentType(): string
    {
        return static::CONTENT_TYPE;
    }

    // --------------------------------------------------------------------------

    /**
     * Formats the return value from the resposne data
     *
     * @param array $aResponse The response data
     *
     * @return string
     */
    public static function render(array $aOut): string
    {
        if (Environment::not(Environment::ENV_PROD) && defined('JSON_PRETTY_PRINT')) {
            return json_encode($aOut, JSON_PRETTY_PRINT);

        } else {
            return json_encode($aOut);
        }
    }
}
