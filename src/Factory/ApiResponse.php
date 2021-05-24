<?php

/**
 * The response object for API Controllers
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Factory
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Api\Factory;

use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\HttpCodes;

/**
 * Class ApiResponse
 *
 * @package Nails\Api\Factory
 */
class ApiResponse
{
    /**
     * The API Response code
     *
     * @var int
     */
    protected $iCode = HttpCodes::STATUS_OK;

    /**
     * If defined, this will be the exact body of the response
     *
     * @var string|null
     */
    protected $sBody = null;

    /**
     * The payload for the response
     *
     * @var mixed
     */
    protected $mData;

    /**
     * Additional data to return
     *
     * @var array
     */
    protected $aMeta = [];

    // --------------------------------------------------------------------------

    /**
     * Set the response code
     *
     * @param int $iCode The API response code to use
     *
     * @return $this
     */
    public function setCode(int $iCode)
    {
        if ($iCode < 100 || $iCode > 299) {
            throw new ValidationException('Response code must be in the range 100-299');
        }

        $this->iCode = $iCode;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the response code
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->iCode;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the body value
     *
     * @param string $sBody
     */
    public function setBody(string $sBody): self
    {
        if (!empty($this->mData)) {
            throw new ValidationException(
                'Cannot set response body when a data has been set'
            );
        }

        $this->sBody = $sBody;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the body
     *
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->sBody;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the response payload
     *
     * @param mixed $mData
     *
     * @return $this
     */
    public function setData($mData)
    {
        if ($this->sBody !== null) {
            throw new ValidationException(
                'Cannot set response data when a body has been set'
            );
        }

        $this->mData = $mData;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the response payload
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->mData;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the response meta
     *
     * @param array $aMeta
     *
     * @return $this
     */
    public function setMeta(array $aMeta)
    {
        $this->aMeta = $aMeta;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the response meta
     *
     * @return array
     */
    public function getMeta()
    {
        return $this->aMeta;
    }
}
