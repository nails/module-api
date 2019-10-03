<?php

namespace Nails\Api\Controller;

use Nails\Api\Exception\ApiException;
use Nails\Api\Factory\ApiResponse;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;

/**
 * Class CrudController
 *
 * @package Nails\Api\Controller
 */
class CrudController extends Base
{
    /**
     * The model to use
     *
     * @var string
     */
    const CONFIG_MODEL_NAME     = '';
    const CONFIG_MODEL_PROVIDER = '';

    /**
     * What to use for looking up resources; ID, SLUG, or TOKEN
     *
     * @var string
     */
    const CONFIG_LOOKUP_METHOD = 'ID';

    /**
     * The $_GET parameter with the search query in it
     *
     * @var string
     */
    const CONFIG_SEARCH_PARAM = 'search';

    /**
     * The $_GET parameter with the ID restrictions in it
     *
     * @var strong
     */
    const CONFIG_IDS_PARAM = 'ids';

    /**
     * The $_GET parameter with the page query in it
     *
     * @var string
     */
    const CONFIG_PAGE_PARAM = 'page';

    /**
     * The number of items to return per page
     *
     * @var integer
     */
    const CONFIG_PER_PAGE = 25;

    /**
     * The default data array to use when looking up an item
     *
     * @var array
     */
    const CONFIG_LOOKUP_DATA = [];

    /**
     * Actions which can be performed
     *
     * @var string
     */
    const ACTION_CREATE = 'CREATE';
    const ACTION_READ   = 'READ';
    const ACTION_UPDATE = 'UPDATE';
    const ACTION_DELETE = 'DELETE';

    /**
     * An array of fields which should be ignored when reading
     *
     * @var array
     */
    const IGNORE_FIELDS_READ = [];

    /**
     * An array of fields which should be ignored when writing
     *
     * @var array
     */
    const IGNORE_FIELDS_WRITE = [
        'id',
        'token',
    ];

    // --------------------------------------------------------------------------

    /**
     * The model instance
     *
     * @var \Nails\Common\Model\Base
     */
    protected $oModel;

    // --------------------------------------------------------------------------

    /**
     * CrudController constructor.
     *
     * @param \ApiRouter $oApiRouter the ApiRouter object
     *
     * @throws NailsException
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

        /** @var \Nails\Common\Model\Base oModel */
        $this->oModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

    }

    // --------------------------------------------------------------------------

    /**
     * Handles GET requests
     *
     * @param string $sMethod The method being called
     * @param array  $aData   Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     */
    public function getRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        if ($oUri->segment(4)) {

            //  Test that there's not an explicit method defined for this request
            $sExplicitMethod = 'get' . ucfirst($oUri->segment(4));
            if (method_exists($this, $sExplicitMethod)) {
                return $this->$sExplicitMethod();
            }

            $aData = $this->getLookupData(static::ACTION_READ, $aData);
            $oItem = $this->lookUpResource($aData);
            if (!$oItem) {
                throw new ApiException(
                    'Resource not found',
                    $oHttpCodes::STATUS_NOT_FOUND
                );
            }

            $this->userCan(static::ACTION_READ, $oItem);
            /** @var ApiResponse $oResponse */
            $oResponse = Factory::factory('ApiResponse', 'nails/module-api');

            //  If there's a submethod defined, call that
            $sSubMethod = $oUri->segment(5);
            if ($sSubMethod && method_exists($this, $sSubMethod)) {
                $this->$sSubMethod($oResponse, $oItem);
            } elseif ($sSubMethod && !method_exists($this, $sSubMethod)) {
                throw new ApiException(
                    '"' . $sSubMethod . '" is not a valid subresource',
                    $oHttpCodes::STATUS_NOT_FOUND
                );
            } else {
                $oResponse->setData($this->formatObject($oItem));
            }

        } else {

            $this->userCan(static::ACTION_READ);

            /** @var Input $oInput */
            $oInput = Factory::service('Input');
            $aData  = $this->getLookupData(static::ACTION_READ, $aData);

            //  Paging
            $iPage = (int) $oInput->get(static::CONFIG_PAGE_PARAM) ?: 1;
            $iPage = $iPage < 0 ? $iPage * -1 : $iPage;

            //  Searching
            if ($oInput->get(static::CONFIG_SEARCH_PARAM)) {
                $aData['keywords'] = $oInput->get(static::CONFIG_SEARCH_PARAM);
            }

            // Requesting specific IDs
            if ($oInput->get(static::CONFIG_IDS_PARAM)) {
                $aData['where_in'] = [
                    [$this->oModel->getcolumn('id'), explode(',', $oInput->get(static::CONFIG_IDS_PARAM))],
                ];
            }

            $iTotal   = $this->oModel->countAll($aData);
            $aResults = array_map(
                [$this, 'formatObject'],
                $this->oModel->getAll(
                    $iPage,
                    static::CONFIG_PER_PAGE,
                    $aData
                )
            );

            /** @var ApiResponse $oResponse */
            $oResponse = Factory::factory('ApiResponse', 'nails/module-api')
                ->setData($aResults)
                ->setMeta([
                    'pagination' => [
                        'page'     => $iPage,
                        'per_page' => static::CONFIG_PER_PAGE,
                        'total'    => $iTotal,
                        'previous' => $this->buildUrl($iTotal, $iPage, -1),
                        'next'     => $this->buildUrl($iTotal, $iPage, 1),
                    ],
                ]);
        }

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles POST requests
     *
     * @param string $sMethod The method being called
     * @param array  $aData   Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     */
    public function postRemap($sMethod, array $aData = [])
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var ApiResponse $oResponse */
        $oResponse = Factory::factory('ApiResponse', 'nails/module-api');

        //  Test that there's not an explicit method defined for this action
        $sExplicitMethod = 'post' . ucfirst($oUri->segment(4));
        if (method_exists($this, $sExplicitMethod)) {
            return $this->$sExplicitMethod();
        }

        if (empty($sMethod) || $sMethod === 'index') {

            //  No method is being called, create a new item
            $this->userCan(static::ACTION_CREATE);

            $aData   = $this->getPostedData();
            $aData   = $this->validateUserInput($aData);
            $iItemId = $this->oModel->create($aData);

            if (empty($iItemId)) {
                throw new ApiException(
                    'Failed to create resource. ' . $this->oModel->lastError(),
                    $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            $aData = $this->getLookupData(static::ACTION_READ, $aData);
            $oItem = $this->oModel->getById($iItemId, $aData);
            $oResponse->setData($this->formatObject($oItem));

            return $oResponse;
        }

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(5);
        if (empty($sSubMethod)) {
            throw new ApiException(
                'A subresource must be specified when posting against an existing item',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        } elseif (!method_exists($this, $sSubMethod)) {
            throw new ApiException(
                '"' . $sSubMethod . '" is not a valid subresource',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $aData = $this->getLookupData(static::ACTION_READ, $aData);
        $oItem = $this->lookUpResource($aData);
        if (!$oItem) {
            throw new ApiException(
                'Resource not found',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $this->$sSubMethod($oResponse, $oItem);

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Updates an existing resource
     *
     * @param string $sMethod The method being called
     *
     * @return array
     */
    public function putRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Test that there's not an explicit method defined for this action
        $sMethod = 'put' . ucfirst($oUri->segment(4));
        if (method_exists($this, $sMethod)) {
            return $this->$sMethod();
        }

        // --------------------------------------------------------------------------

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(5);
        if (!empty($sSubMethod) && !method_exists($this, $sSubMethod)) {
            throw new ApiException(
                '"' . $sSubMethod . '" is not a valid subresource',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $aData = $this->getLookupData(static::ACTION_UPDATE, $aData);
        $oItem = $this->lookUpResource($aData);
        if (!$oItem) {
            throw new ApiException(
                'Resource not found',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', 'nails/module-api');

        if (empty($sSubMethod)) {

            //  Read from php:://input as using PUT; expecting a JSONobject as the payload
            $aData = $this->getPostedData();
            $aData = $this->validateUserInput($aData, $oItem);

            if (!$this->oModel->update($oItem->id, $aData)) {
                throw new ApiException(
                    'Failed to update resource. ' . $this->oModel->lastError(),
                    $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                );
            }

        } else {
            $this->$sSubMethod($oApiResponse, $oItem);
        }

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing resource
     *
     * @param string $sMethod The method being called
     *
     * @return array
     */
    public function deleteRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Test that there's not an explicit method defined for this action
        $sMethod = 'delete' . ucfirst($oUri->segment(4));
        if (method_exists($this, $sMethod)) {
            return $this->$sMethod();
        }

        // --------------------------------------------------------------------------

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(5);
        if (!empty($sSubMethod) && !method_exists($this, $sSubMethod)) {
            throw new ApiException(
                '"' . $sSubMethod . '" is not a valid subresource',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $aData = $this->getLookupData(static::ACTION_DELETE, $aData);
        $oItem = $this->lookUpResource($aData);
        if (!$oItem) {
            throw new ApiException(
                'Resource not found',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', 'nails/module-api');

        if (empty($sSubMethod)) {

            $this->userCan(static::ACTION_DELETE, $oItem);

            if (!$this->oModel->delete($oItem->id)) {
                throw new ApiException(
                    'Failed to delete resource. ' . $this->oModel->lastError(),
                    $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
                );
            }

        } else {
            $this->$sSubMethod($oApiResponse, $oItem);
        }

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches an object by it's ID, SLUG, or TOKEN
     *
     * @param array   $aData    Any data to pass to the lookup
     * @param integer $iSegment The segment containing the item's ID/Token/Slug
     *
     * @return \Nails\Common\Resource\|false
     * @throws FactoryException
     */
    protected function lookUpResource($aData = [], $iSegment = 4)
    {
        /** @var Uri $oUri */
        $oUri        = Factory::service('Uri');
        $sIdentifier = $oUri->segment($iSegment);

        //  Handle requests for expansions
        /** @var Input $oInput */
        $oInput      = Factory::service('Input');
        $aExpansions = array_filter((array) $oInput->get('expand'));
        if ($aExpansions) {
            if (!array_key_exists('expand', $aData)) {
                $aData['expand'] = [];
            }

            $aData['expand'] = array_merge($aData['expand'], $aExpansions);
        }

        switch (static::CONFIG_LOOKUP_METHOD) {
            case 'ID':
                return $this->oModel->getById($sIdentifier, $aData);
                break;
            case 'SLUG':
                return $this->oModel->getBySlug($sIdentifier, $aData);
                break;
            case 'TOKEN':
                return $this->oModel->getByToken($sIdentifier, $aData);
                break;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Provides a hook for manipulating the lookup data
     *
     * @param string $sMode The lookup mode
     * @param array  $aData The lookup data
     *
     * @return array
     */
    protected function getLookupData(string $sMode, array $aData): array
    {
        //  This method should be overridden to implement specific behaviour
        return array_merge(static::CONFIG_LOOKUP_DATA, $aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Validates a user can perform this action
     *
     * @param string    $sAction The action being performed
     * @param \stdClass $oItem   The item the action is being performed against
     *
     * @throws ApiException
     */
    protected function userCan($sAction, $oItem = null)
    {
        //  By default users can perform any action, apply restrictions by overloading this method
    }

    // --------------------------------------------------------------------------

    /**
     * Validates user input
     *
     * @param array     $aData The user data to validate
     * @param \stdClass $oItem The current object (when editing)
     *
     * @return array
     * @throws ApiException;
     */
    protected function validateUserInput($aData, $oItem = null)
    {
        $aOut       = [];
        $aFields    = $this->oModel->describeFields();
        $aKeys      = array_unique(
            array_merge(
                array_keys($aFields),
                arrayExtractProperty($this->oModel->getExpandableFields(), 'trigger')
            )
        );
        $aValidKeys = array_diff($aKeys, static::IGNORE_FIELDS_WRITE);

        foreach ($aValidKeys as $sValidKey) {

            $oField = getFromArray($sValidKey, $aFields);
            if (array_key_exists($sValidKey, $aData)) {
                $aOut[$sValidKey] = getFromArray($sValidKey, $aData);
            }

            //  @todo (Pablo - 2018-08-20) - Further validation using the $oField->validation rules
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Builds pagination URL
     *
     * @param integer $iTotal      The total number of items
     * @param integer $iPage       The current page number
     * @param integer $iPageOffset The offset to the page number
     *
     * @return null|string
     */
    protected function buildUrl($iTotal, $iPage, $iPageOffset)
    {
        $aParams = [
            'page' => $iPage + $iPageOffset,
        ];

        if ($aParams['page'] <= 0) {
            return null;
        } elseif ($aParams['page'] === 1) {
            unset($aParams['page']);
        }

        $iTotalPages = ceil($iTotal / static::CONFIG_PER_PAGE);
        if (!empty($aParams['page']) && $aParams['page'] > $iTotalPages) {
            return null;
        }

        $sUrl = siteUrl() . uri_string();

        if (!empty($aParams)) {
            $sUrl .= '?' . http_build_query($aParams);
        }

        return $sUrl;
    }

    // --------------------------------------------------------------------------

    /**
     * Formats an object
     *
     * @param \stdClass $oObj The object to format
     *
     * @return \stdClass
     */
    protected function formatObject($oObj)
    {
        foreach (static::IGNORE_FIELDS_READ as $sIgnoredField) {
            unset($oObj->{$sIgnoredField});
        }
        return $oObj;
    }

    // --------------------------------------------------------------------------

    /**
     * Gets the request data from the POST vars, falling back to the request body
     *
     * @return array
     * @throws FactoryException
     */
    protected function getPostedData(): array
    {
        /**
         * First check the $_POST superglobal, if that's empty then fall back to
         * the body of the request assuming it is JSON.
         */
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $aData  = $oInput->post();

        if (empty($aData)) {
            $sData = stream_get_contents(fopen('php://input', 'r'));
            $aData = json_decode($sData, JSON_OBJECT_AS_ARRAY) ?: [];
        }

        return $aData;
    }
}
