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

use Nails\Api\Exception\ApiException;
use Nails\Factory;

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

    /**
     * Which fields to ignore when POSTing
     */
    const CONFIG_POST_IGNORE_FIELDS = ['id', 'slug', 'created', 'is_deleted', 'created_by', 'modified', 'modified_by'];

    // --------------------------------------------------------------------------

    /**
     * DefaultController constructor.
     *
     * @throws ApiException
     *
     * @param $oApiRouter
     */
    public function __construct($oApiRouter)
    {
        parent::__construct($oApiRouter);

        if (empty(static::CONFIG_MODEL_NAME)) {
            throw new ApiException('"static::CONFIG_MODEL_NAME" is required.');
        }
        if (empty(static::CONFIG_MODEL_PROVIDER)) {
            throw new ApiException('"static::CONFIG_MODEL_PROVIDER" is required.');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Get multiple items
     *
     * @param  array   $aData    Any data to pass to the model
     * @param  integer $iPage    The page to display
     * @param  integer $iPerPage The number of items to display at the moment
     *
     * @return array
     */
    public function getIndex($aData = [], $iPage = null, $iPerPage = null)
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

        $aResults = $oItemModel->getAll(
            $iPage,
            $iPerPage,
            $aData
        );

        //  @todo (Pablo - 2018-06-24) - Paging
        $oResponse = Factory::factory('ApiResponse', 'nails/module-api');
        $oResponse->setData(array_map([$this, 'formatObject'], $aResults));
        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Return an item by it's ID, or an array of items by their ID.
     *
     * @return array
     */
    public function getId($aData = [])
    {
        $sIds       = '';
        $oInput     = Factory::service('Input');
        $oHttpCodes = Factory::service('HttpCodes');

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
            throw new ApiException(
                'You can request a maximum of ' . self::CONFIG_MAX_ITEMS_PER_REQUEST . ' items per request',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }

        // --------------------------------------------------------------------------

        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );
        $aResults   = $oItemModel->getByIds($aIds, $aData);

        if ($oInput->get('id')) {
            $oItem = reset($aResults);
            $mData = $oItem ? $this->formatObject($oItem) : null;
        } else {
            $mData = array_map([$this, 'formatObject'], $aResults);
        }

        $oResponse = Factory::factory('ApiResponse', 'nails/module-api');
        $oResponse->setData($mData);
        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Search for an item
     *
     * @param array $aData The configuration array
     *
     * @return array
     */
    public function getSearch($aData = [])
    {
        $oInput     = Factory::service('Input');
        $oHttpCodes = Factory::service('HttpCodes');
        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );
        $sKeywords  = $oInput->get('search') ?: $oInput->get('keywords');
        $iPage      = (int) $oInput->get('page');

        if (strlen($sKeywords) < static::CONFIG_MIN_SEARCH_LENGTH) {
            throw new ApiException(
                'Search term must be ' . static::CONFIG_MIN_SEARCH_LENGTH . ' characters or longer.',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }

        $oResult = $oItemModel->search(
            $sKeywords,
            $iPage,
            static::CONFIG_MAX_ITEMS_PER_PAGE,
            $aData
        );

        $oResponse = Factory::factory('ApiResponse', 'nails/module-api');
        $oResponse->setData(array_map([$this, 'formatObject'], $oResult->data));
        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new item
     *
     * @return array
     */
    public function postRemap()
    {
        $oUri = Factory::service('Uri');
        //  Test that there's not an explicit method defined for this
        $sMethod = 'post' . ucfirst($oUri->segment(4));
        if (method_exists($this, $sMethod)) {
            return $this->$sMethod();
        }

        $oInput     = Factory::service('Input');
        $oHttpCodes = Factory::service('HttpCodes');
        $oItemModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );
        //  Validate fields
        $aFields  = $oItemModel->describeFields();
        $aValid   = [];
        $aInvalid = [];
        foreach ($aFields as $oField) {
            if (in_array($oField->key, static::CONFIG_POST_IGNORE_FIELDS)) {
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
            throw new ApiException(
                'The following arguments are invalid: ' . implode(', ', $aInvalid),
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }

        $iItemId = (int) $oUri->segment(4);
        if ($iItemId) {
            $oItem = $oItemModel->getById($iItemId);
            if (empty($oItem)) {
                throw new ApiException(
                    'Item does not exist',
                    $oHttpCodes::STATUS_NOT_FOUND
                );
            } elseif (!$oItemModel->update($iItemId, $aPost)) {
                throw new ApiException(
                    'Failed to update item. ' . $oItemModel->lastError(),
                    $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                );
            } elseif (classUses($oItemModel, 'Nails\Common\Traits\Caching')) {
                $oItemModel->disableCache();
            }
            $oItem = $oItemModel->getById($iItemId);
            if (classUses($oItemModel, 'Nails\Common\Traits\Caching')) {
                $oItemModel->enableCache();
            }
        } else {
            $oItem = $oItemModel->create($aPost, true);
        }

        $oResponse = Factory::factory('ApiResponse', 'nails/module-api');
        $oResponse->setData($this->formatObject($oItem));
        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Format the output
     *
     * @param \stdClass $oObj The object to format
     *
     * @return array
     */
    protected function formatObject($oObj)
    {
        return [
            'id'    => $oObj->id,
            'label' => $oObj->label,
        ];
    }
}
