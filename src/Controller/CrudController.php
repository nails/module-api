<?php

namespace Nails\Api\Controller;

use Nails\Api\Exception\ApiException;
use Nails\Factory;

class CrudController extends Base
{
    /**
     * The model to use
     * @var string
     */
    const CONFIG_MODEL_NAME     = '';
    const CONFIG_MODEL_PROVIDER = '';

    /**
     * What to use for looking up resources; ID, SLUG, or TOKEN
     * @var string
     */
    const CONFIG_LOOKUP_METHOD = 'ID';

    /**
     * The $_GET parameter with the search query in it
     * @var string
     */
    const CONFIG_SEARCH_PARAM = 'search';

    /**
     * The $_GET parameter with the page query in it
     * @var integer
     */
    const CONFIG_PAGE_PARAM = 'page';

    /**
     * The number of items to return per page
     * @var integer
     */
    const CONFIG_PER_PAGE = 25;

    // --------------------------------------------------------------------------

    /**
     * The model instance
     * @var Nails\Common\Model\Base
     */
    protected $oModel;

    // --------------------------------------------------------------------------

    /**
     * CrudController constructor.
     *
     * @param \ApiRouter $oApiRouter the ApiRouter object
     *
     * @throws \Exception
     */
    public function __construct($oApiRouter)
    {
        parent::__construct($oApiRouter);

        if (empty(static::CONFIG_MODEL_NAME)) {
            throw new \Exception('"static::CONFIG_MODEL_NAME" is required.');
        }
        if (empty(static::CONFIG_MODEL_PROVIDER)) {
            throw new \Exception('"static::CONFIG_MODEL_PROVIDER" is required.');
        }

        $this->oModel = Factory::model(
            static::CONFIG_MODEL_NAME,
            static::CONFIG_MODEL_PROVIDER
        );

    }

    // --------------------------------------------------------------------------

    /**
     * Handles GET requests
     * @return array
     */
    public function getRemap()
    {
        //  @todo (Pablo - 2018-05-08) - Validate user can view resource

        $oUri       = Factory::service('Uri');
        $oHttpCodes = Factory::service('HttpCodes');
        if ($oUri->segment(4)) {

            $oItem = $this->lookUpResource();
            if (!$oItem) {
                throw new ApiException(
                    'Resource not found',
                    $oHttpCodes::STATUS_NOT_FOUND
                );
            }

            $oResponse = Factory::factory('ApiResponse', 'nailsapp/module-api');
            $oResponse->setData($this->formatObject($oItem));

        } else {

            $oInput = Factory::service('Input');
            $aData  = [];

            //  Paging
            $iPage = (int) $oInput->get(static::CONFIG_PAGE_PARAM) ?: 1;
            $iPage = $iPage < 0 ? $iPage * -1 : $iPage;

            //  Searching
            if ($oInput->get(static::CONFIG_SEARCH_PARAM)) {
                $aData['keywords'] = $oInput->get(static::CONFIG_SEARCH_PARAM);
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

            $oResponse = Factory::factory('ApiResponse', 'nailsapp/module-api')
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
     * Creates a new resource
     * @return array
     */
    public function postIndex()
    {

        //  @todo (Pablo - 2018-05-08) - Validate user can create resource
        //  @todo (Pablo - 2018-05-08) - Validate
        //  @todo (Pablo - 2018-05-08) - Create
        //  @todo (Pablo - 2018-05-08) - Extract fields safely

        $oInput     = Factory::service('Input');
        $oHttpCodes = Factory::service('HttpCodes');
        $aData      = $oInput->post();

        $oItem = $this->oModel->create($aData, true);
        if (empty($oItem)) {
            throw new ApiException(
                'Failed to create resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $oResponse = Factory::factory('ApiResponse', 'nailsapp/module-api');
        $oResponse->setData($this->formatObject($oItem));

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Updates an existing resource
     * @return array
     */
    public function putRemap()
    {
        $oItem = $this->lookUpResource();
        if (!$oItem) {
            throw new ApiException('Resource not found', 404);
        }

        //  @todo (Pablo - 2018-05-08) - Validate user can update resource
        //  @todo (Pablo - 2018-05-08) - Validate
        //  @todo (Pablo - 2018-05-08) - Update
        //  @todo (Pablo - 2018-05-08) - Extract fields safely

        $oInput     = Factory::service('Input');
        $oHttpCodes = Factory::service('HttpCodes');
        $aData      = $oInput->post();

        if (!$this->oModel->update($oItem->id, $aData)) {
            throw new ApiException(
                'Failed to update resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return Factory::factory('ApiResponse', 'nailsapp/module-api');
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing resource
     * @return array
     */
    public function deleteRemap()
    {
        $oHttpCodes = Factory::service('HttpCodes');
        $oItem      = $this->lookUpResource();
        if (!$oItem) {
            throw new ApiException(
                'Resource not found',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        //  @todo (Pablo - 2018-05-08) - Validate user can delete resource

        if (!$this->oModel->delete($oItem->id)) {
            throw new ApiException(
                'Failed to delete resource. ' . $this->oModel->lastError(),
                $oHttpCodes::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return Factory::factory('ApiResponse', 'nailsapp/module-api');
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches an object by it's ID, SLUG, or TOKEN
     * @return \stdClass|false
     */
    protected function lookUpResource()
    {
        $oUri        = Factory::service('Uri');
        $sIdentifier = $oUri->segment(4);

        switch (static::CONFIG_LOOKUP_METHOD) {
            case 'ID':
                return $this->oModel->getById($sIdentifier);
                break;
            case 'SLUG':
                return $this->oModel->getBySlug($sIdentifier);
                break;
            case 'TOKEN':
                return $this->oModel->getByToken($sIdentifier);
                break;

        }
    }

    // --------------------------------------------------------------------------

    protected function buildUrl($iTotal, $iPage, $iOffset)
    {
        $aParams = [
            'page' => $iPage + $iOffset,
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

        $sUrl = site_url() . uri_string();

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
        return $oObj;
    }
}
