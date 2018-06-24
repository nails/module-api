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

class ApiResponse
{
    /**
     * The payload for the response
     * @var mixed
     */
    protected $mData;

    /**
     * Additional data to return
     * @var array
     */
    protected $aMeta = [];

    // --------------------------------------------------------------------------

    /**
     * Set the response payload
     * @param $mData
     */
    public function setData($mData)
    {
        $this->mData = $mData;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the response payload
     * @return mixed
     */
    public function getData()
    {
        return $this->mData;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the response meta
     * @param array $aMeta
     */
    public function setMeta(array $aMeta)
    {
        $this->mMeta = $aMeta;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the response meta
     * @return array
     */
    public function getMeta()
    {
        return $this->aMeta;
    }
}
