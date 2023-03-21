<?php

namespace Nails\Api\Controller;

use ApiRouter;
use Nails\Api\Constants;
use Nails\Api\Exception\ApiException;
use Nails\Api\Factory\ApiResponse;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Helper\ArrayHelper;
use Nails\Common\Resource;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;
use ReflectionException;

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
    const CONFIG_MODEL_NAME = '';

    /**
     * The model's provider
     *
     * @var string
     */
    const CONFIG_MODEL_PROVIDER = '';

    /**
     * The URI segment which will contain the item's identifier
     *
     * @var int
     */
    const CONFIG_URI_SEGMENT_IDENTIFIER = 4;

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
     * @var string
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
     * @var int
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
    const ACTION_LIST   = 'LIST';
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
     * @param ApiRouter $oApiRouter the ApiRouter object
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws ReflectionException
     * @throws NailsException
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

        $this->oModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

    }

    /** -------------------------------------------------------------------------
     * ROUTING METHODS
     * The following methods route requests to the appropriate CRUD method
     * --------------------------------------------------------------------------
     */

    /**
     * Handles GET requests
     *
     * @param string $sMethod The method being called
     * @param array  $aData   Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    public function getRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        if ($oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER)) {

            //  Test that there's not an explicit method defined for this request
            $sExplicitMethod = 'get' . ucfirst($oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER));
            if (method_exists($this, $sExplicitMethod)) {
                return $this->$sExplicitMethod();
            }

            return $this->read($aData);

        } else {
            return $this->list($aData);
        }
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
     * @throws ModelException
     * @throws ValidationException
     */
    public function postRemap($sMethod, array $aData = [])
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        //  Test that there's not an explicit method defined for this action
        $sSegment = $oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER);
        if ($sSegment) {
            $sExplicitMethod = 'post' . ucfirst($sSegment);
            if (method_exists($this, $sExplicitMethod)) {
                return $this->$sExplicitMethod();
            }
        }

        if (empty($sMethod) || $sMethod === 'index') {
            return $this->create();
        }

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER + 1);
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

        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG);

        $this->$sSubMethod($oApiResponse, $oItem);

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles PUT requests
     *
     * @param string $sMethod The method being called
     * @param array  $aData   Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    public function putRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Test that there's not an explicit method defined for this action
        $sMethod = 'put' . ucfirst($oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER));
        if (method_exists($this, $sMethod)) {
            return $this->$sMethod();
        }

        // --------------------------------------------------------------------------

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER + 1);
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
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG);

        if (empty($sSubMethod)) {
            $this->update($oApiResponse, $oItem);
        } else {
            $this->$sSubMethod($oApiResponse, $oItem);
        }

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles DELETE requests
     *
     * @param string $sMethod The method being called
     * @param array  $aData   Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    public function deleteRemap($sMethod, array $aData = [])
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Test that there's not an explicit method defined for this action
        $sMethod = 'delete' . ucfirst($oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER));
        if (method_exists($this, $sMethod)) {
            return $this->$sMethod();
        }

        // --------------------------------------------------------------------------

        //  If there's a submethod defined, verify that the resource is valid and then call the sub method
        $sSubMethod = $oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER + 1);
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
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG);

        if (empty($sSubMethod)) {
            $this->delete($oApiResponse, $oItem);
        } else {
            $this->$sSubMethod($oApiResponse, $oItem);
        }

        return $oApiResponse;
    }

    /** -------------------------------------------------------------------------
     * CRUD METHODS
     * The following methods provide (C)reate (R)ead (U)pdate and (D)elete
     * functionality, as well as a listing method for browsing resources.
     * --------------------------------------------------------------------------
     */

    /**
     * Lists resources in a paginated fashion
     *
     * @param array $aData Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws FactoryException
     * @throws ModelException
     */
    protected function list(array $aData = []): ApiResponse
    {
        $this->userCan(static::ACTION_LIST);

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $aData  = $this->getLookupData(static::ACTION_READ, $aData);

        //  Paging
        $iPage = (int) $oInput->get(static::CONFIG_PAGE_PARAM) ?: 1;
        $iPage = $iPage < 0 ? $iPage * -1 : $iPage;

        //  Searching
        if ($oInput->get(static::CONFIG_SEARCH_PARAM)) {
            $aData['keywords'] = utf8_encode($oInput->get(static::CONFIG_SEARCH_PARAM));
        }

        // Requesting specific IDs
        if ($oInput->get(static::CONFIG_IDS_PARAM)) {
            $aData['where_in'] = [
                [$this->oModel->getcolumn('id'), explode(',', $oInput->get(static::CONFIG_IDS_PARAM))],
            ];
        }

        $iTotal = $this->oModel->countAll($aData);
        /** @var \Nails\Common\Resource\Entity[] $aResults */
        $aResults = $this->oModel->getAll($iPage, static::CONFIG_PER_PAGE, $aData);

        if ($oInput->get(static::CONFIG_IDS_PARAM)) {
            //  Put these in the requested order
            $aSorted = [];
            foreach (explode(',', $oInput->get(static::CONFIG_IDS_PARAM)) as $sId) {
                foreach ($aResults as $oResult) {
                    if ($oResult->id === (int) $sId) {
                        $aSorted[] = $oResult;
                    }
                }
            }
            $aResults = $aSorted;
        }

        $aResults = array_map([$this, 'formatObject'], $aResults);

        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG)
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

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new resource
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    protected function create(): ApiResponse
    {
        $this->userCan(static::ACTION_CREATE);

        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');
        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG);

        $aData   = $this->getRequestData();
        $aData   = $this->validateUserInput($aData);
        $iItemId = $this->oModel->create($aData);

        if (empty($iItemId)) {
            throw new ApiException(
                'Failed to create resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $aData = $this->getLookupData(static::ACTION_READ, $aData);
        /** @var Resource\Entity $oItem */
        $oItem = $this->oModel->getById($iItemId, $aData);
        $oApiResponse->setData($this->formatObject($oItem));

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a single resource
     *
     * @param array $aData Any data to apply to the requests
     *
     * @return ApiResponse
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function read(array $aData = []): ApiResponse
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        $aData = $this->getLookupData(static::ACTION_READ, $aData);
        $oItem = $this->lookUpResource($aData);
        if (!$oItem) {
            throw new ApiException(
                'Resource not found',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $this->userCan(static::ACTION_READ, $oItem);
        /** @var ApiResponse $oApiResponse */
        $oApiResponse = Factory::factory('ApiResponse', Constants::MODULE_SLUG);

        //  If there's a submethod defined, call that
        $sSubMethod = $oUri->segment(static::CONFIG_URI_SEGMENT_IDENTIFIER + 1);
        if ($sSubMethod && method_exists($this, $sSubMethod)) {
            $this->$sSubMethod($oApiResponse, $oItem);
        } elseif ($sSubMethod && !method_exists($this, $sSubMethod)) {
            throw new ApiException(
                '"' . $sSubMethod . '" is not a valid subresource',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        } else {
            $oApiResponse->setData($this->formatObject($oItem));
        }

        return $oApiResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Updates an existing resource
     *
     * @param ApiResponse     $oApiResponse The API Response
     * @param Resource\Entity $oItem        The item resource being updated
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    protected function update(ApiResponse $oApiResponse, Resource\Entity $oItem): void
    {
        $this->userCan(static::ACTION_UPDATE, $oItem);

        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        //  Read from php:://input as using PUT; expecting a JSON object as the payload
        $aData = $this->getRequestData();
        $aData = $this->validateUserInput($aData, $oItem);

        if (!$this->oModel->update($oItem->id, $aData)) {
            throw new ApiException(
                'Failed to update resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing resource
     *
     * @param ApiResponse     $oApiResponse The API Response
     * @param Resource\Entity $oItem        The item resource being updated
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function delete(ApiResponse $oApiResponse, Resource\Entity $oItem): void
    {
        $this->userCan(static::ACTION_DELETE, $oItem);

        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        if (!$this->oModel->delete($oItem->id)) {
            throw new ApiException(
                'Failed to delete resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /** -------------------------------------------------------------------------
     * UTILITY METHODS
     * The following methods are utility methods which provide additional bits
     * of functionality and/or hooks for overloading code.
     * --------------------------------------------------------------------------
     */

    /**
     * Fetches an object by it's ID, SLUG, or TOKEN
     *
     * @param array $aData    Any data to pass to the lookup
     * @param int   $iSegment The segment containing the item's ID/Token/Slug
     *
     * @return Resource\Entity|null
     * @throws FactoryException
     * @throws ModelException
     */
    protected function lookUpResource($aData = [], $iSegment = null): ?Resource\Entity
    {
        if ($iSegment === null) {
            $iSegment = static::CONFIG_URI_SEGMENT_IDENTIFIER;
        }

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
                $oItem = $this->oModel->getById($sIdentifier, $aData);
                break;

            case 'SLUG':
                $oItem = $this->oModel->getBySlug($sIdentifier, $aData);
                break;

            case 'TOKEN':
                $oItem = $this->oModel->getByToken($sIdentifier, $aData);
                break;

            default:
                throw new ApiException(sprintf(
                    'Value for %s::CONFIG_LOOKUP_METHOD is invalid',
                    static::class
                ));
        }

        /** @var Resource\Entity $oItem */
        return $oItem;
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
     * @param string          $sAction The action being performed
     * @param Resource\Entity $oItem   The item the action is being performed against
     */
    protected function userCan($sAction, Resource\Entity $oItem = null)
    {
        /**
         * By default users can perform any action, apply restrictions by
         * overloading this method and throwing a ValidationException.
         */
    }

    // --------------------------------------------------------------------------

    /**
     * Validates user input
     *
     * @param array           $aData The user data to validate
     * @param Resource\Entity $oItem The current object (when editing)
     *
     * @return array
     * @throws FactoryException
     * @throws ValidationException
     */
    protected function validateUserInput($aData, Resource\Entity $oItem = null)
    {
        $aOut    = [];
        $aFields = $this->oModel->describeFields();
        $aKeys   = array_unique(
            array_merge(
                array_keys($aFields),
                ArrayHelper::extract($this->oModel->getExpandableFields(), 'trigger')
            )
        );

        $aValidKeys = array_diff($aKeys, static::IGNORE_FIELDS_WRITE);
        $aRules     = [];

        foreach ($aValidKeys as $sValidKey) {

            $oField = ArrayHelper::get($sValidKey, $aFields);
            if (array_key_exists($sValidKey, $aData)) {

                $aOut[$sValidKey] = ArrayHelper::get($sValidKey, $aData);
                $oField           = ArrayHelper::get($sValidKey, $aFields);

                if (!empty($oField->validation)) {
                    $aRules[$sValidKey] = $oField->validation;
                }
            }
        }

        /** @var FormValidation $oFormValidation */
        $oFormValidation = Factory::service('FormValidation');
        $oFormValidation
            ->buildValidator($aRules, [], $aOut)
            ->run();

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Builds pagination URL
     *
     * @param int $iTotal      The total number of items
     * @param int $iPage       The current page number
     * @param int $iPageOffset The offset to the page number
     *
     * @return null|string
     * @throws FactoryException
     */
    protected function buildUrl($iTotal, $iPage, $iPageOffset)
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        $aParams = array_merge(
            $oInput->get(),
            [
                'page' => $iPage + $iPageOffset,
            ]
        );

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
     * @param Resource\Entity $oObj The object to format
     *
     * @return Resource\Entity
     */
    protected function formatObject(Resource\Entity $oObj)
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
     * @deprecated
     */
    protected function getPostedData(): array
    {
        deprecatedError(__METHOD__, 'Base::getRequestData');
        return parent::getRequestData();
    }
}
