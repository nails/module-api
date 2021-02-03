<?php

namespace Nails\Api\Interfaces;

/**
 * Interface Output
 *
 * @package Nails\Api\Interfaces
 */
interface Output
{
    /**
     * Returns the slug which will trigger the output format
     *
     * @return string
     */
    public static function getSlug(): string;

    // --------------------------------------------------------------------------

    /**
     * Returns the content type for the output format
     *
     * @return string
     */
    public static function getContentType(): string;

    // --------------------------------------------------------------------------

    /**
     * Formats the return value from the resposne data
     *
     * @param array $aResponse The response data
     *
     * @return string
     */
    public static function render(array $aResponse): string;
}
