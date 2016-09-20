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

namespace Nails\Api\Controller;

use Nails\Factory;
use Nails\Common\Exception\NailsException;

class DefaultController extends Base
{
    /**
     * The model to use
     */
    const CONFIG_MODEL_NAME     = '';
    const CONFIG_MODEL_PROVIDER = '';

    /**
     * The minimum length of string required for a search to be accepted
     */
    const CONFIG_MIN_SEARCH_LENGTH = 0;

    /**
     * The maximum number of items a user can request at once.
     */
    const CONFIG_MAX_ITEMS_PER_REQUEST = 100;

    /**
     * The maximum number of items to return per page
     */
    const CONFIG_MAX_ITEMS_PER_PAGE = 10;

    // --------------------------------------------------------------------------

    /**
     * DefaultController constructor.
     * @throws NailsException
     * @param $oApiRouter
     */
    public function __construct($oApiRouter)
    {
        parent::__construct($oApiRouter);

        if (empty(static::CONFIG_MODEL_NAME)) {
            throw new NailsException('"static::CONFIG_MODEL_NAME" is required.');
        }
        if (empty(static::CONFIG_MODEL_PROVIDER)) {
            throw new NailsException('"static::CONFIG_MODEL_PROVIDER" is required.');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Get multiple items
     * @param  array   $aData    Any data to pass to the model
     * @param  integer $iPage    The page to display
     * @param  integer $iPerPage The number of items to display at the moment
     * @return array
     */
    public function getIndex($aData = array(), $iPage = null, $iPerPage = null)
    {
        $oInput     = Factory::service('Input');
        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

        if (is_null($iPage)) {
            $iPage = (int) $oInput->get('page') ?: 1;
        }

        if (is_null($iPerPage)) {
            $iPerPage = static::CONFIG_MAX_ITEMS_PER_PAGE;
        }

        $aOut     = array();
        $aResults = $oItemModel->getAll(
            $iPage,
            $iPerPage,
            $aData
        );

        foreach ($aResults as $oItem) {
            $aOut[] = $this->formatObject($oItem);
        }

        //  @todo - paging
        return array(
            'data' => $aOut
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Return an item by it's ID, or an array of items by their ID.
     * @return array
     */
    public function getId()
    {
        $sIds   = '';
        $oInput = Factory::service('Input');

        if (!empty($oInput->get('id'))) {
            $sIds = $oInput->get('id');
        }

        if (!empty($oInput->get('ids'))) {
            $sIds = $oInput->get('ids');
        }

        $aIds = explode(',', $sIds);
        $aIds = array_filter($aIds);
        $aIds = array_unique($aIds);

        if (count($aIds) > self::CONFIG_MAX_ITEMS_PER_REQUEST) {
            return array(
                'status' => 400,
                'error'  => 'You can request a maximum of ' . self::CONFIG_MAX_ITEMS_PER_REQUEST . ' items per request'
            );
        }

        // --------------------------------------------------------------------------

        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );
        $aResults   = $oItemModel->getByIds($aIds);
        $aOut       = array();

        foreach ($aResults as $oItem) {
            $aOut[] = $this->formatObject($oItem);
        }

        if ($oInput->get('id')) {
            return array('data' => !empty($aOut[0]) ? $aOut[0] : null);
        } else {
            return array('data' => $aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Search for an item
     * @return array
     */
    public function getSearch()
    {
        $oInput     = Factory::service('Input');
        $sKeywords  = $oInput->get('keywords');
        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

        if (strlen($sKeywords) >= static::CONFIG_MIN_SEARCH_LENGTH) {
            $oResult = $oItemModel->search($sKeywords);
            $aOut    = array();

            foreach ($oResult->data as $oItem) {
                $aOut[] = $this->formatObject($oItem);
            }

            return array(
                'data' => $aOut
            );
        } else {
            return array(
                'status' => 400,
                'error' => 'Search term must be ' . static::CONFIG_MIN_SEARCH_LENGTH . ' characters or longer.'
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new item
     * @return array
     */
    public function postIndex()
    {
        $oInput     = Factory::service('Input');
        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

        try {

            //  Validate fields
            $aIgnore  = array('id', 'slug', 'created', 'is_deleted', 'created_by', 'modified', 'modified_by');
            $aFields  = $oItemModel->describeFields();
            $aValid   = array();
            $aInvalid = array();
            foreach ($aFields as $oField) {
                if (in_array($oField->key, $aIgnore)) {
                    continue;
                }
                $aValid[] = $oField->key;
            }

            $aPost = $oInput->post();
            foreach ($aPost as $sKey => $sValue) {
                if (!in_array($sKey, $aValid)) {
                    $aInvalid[] = $sKey;
                }
            }

            if (!empty($aInvalid)) {
                throw new \Exception('The following arguments are invalid: ' . implode(', ', $aInvalid), 400);
            }

            $oItem = $oItemModel->create($aPost, true);
            $aOut  = array(
                'data' => $this->formatObject($oItem)
            );

        } catch (\Exception $e) {
            $aOut = array(
                'status' => $e->getCode(),
                'error'  => $e->getMessage()
            );
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Format the output
     * @param \stdClass $oObj The object to format
     * @return array
     */
    protected function formatObject($oObj)
    {
        return array(
            'id'    => $oObj->id,
            'label' => $oObj->label
        );
    }
}
