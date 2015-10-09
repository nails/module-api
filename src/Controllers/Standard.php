<?php

/**
 * This class builds an entire API interface for content that is managed using a model. It makes some assumptions:
 *
 * - The model does not deviate from the standards defined by the base model
 * - There is only one model to work with
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Api\Controllers;

class Standard extends Base
{
    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();
    }
}
