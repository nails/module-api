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
     * The namespace to use for the returned data
     * @var string
     */
    const CONFIG_RESPONSE_NAMESPACE = 'data';

    /**
     * What to use for looking up resources; ID, SLUG, or TOKEN
     * @var string
     */
    const CONFIG_LOOKUP_METHOD = 'ID';

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
     * @throws ApiException
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

    // --------------------------------------------------------------------------

    /**
     * Handles GET requests
     * @return array
     */
    public function getRemap()
    {
        $oUri = Factory::service('Uri');
        if ($oUri->segment(4)) {

            try {

                $oItem = $this->lookUpResource();
                if (!$oItem) {
                    throw new ApiException('Resource not found', 404);
                }

                //  @todo (Pablo - 2018-05-08) - Validate user can view resource

                return [
                    static::CONFIG_RESPONSE_NAMESPACE => $this->formatObject($oItem),
                ];

            } catch (\Exception $e) {
                return [
                    'status' => (int) $e->getCode() ?: 500,
                    'error'  => $e->getMessage(),
                ];
            }

        } else {
            return [
                static::CONFIG_RESPONSE_NAMESPACE => array_map([$this, 'formatObject'], $this->oModel->getAll()),
            ];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new resource
     * @return array
     */
    public function postIndex()
    {
        try {

            //  @todo (Pablo - 2018-05-08) - Validate user can create resource
            //  @todo (Pablo - 2018-05-08) - Validate
            //  @todo (Pablo - 2018-05-08) - Create

            //  @todo (Pablo - 2018-05-08) - Extract fields safely
            $oInput = Factory::service('Input');
            $aData  = $oInput->post();

            $oItem = $this->oModel->create($aData, true);

            return [
                static::CONFIG_RESPONSE_NAMESPACE => $oItem,
            ];

        } catch (\Exception $e) {
            return [
                'status' => (int) $e->getCode() ?: 500,
                'error'  => $e->getMessage(),
            ];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Updates an existing resource
     * @return array
     */
    public function putRemap()
    {
        try {

            $oItem = $this->lookUpResource();
            if (!$oItem) {
                throw new ApiException('Resource not found', 404);
            }

            //  @todo (Pablo - 2018-05-08) - Validate user can update resource
            //  @todo (Pablo - 2018-05-08) - Validate
            //  @todo (Pablo - 2018-05-08) - Update

            //  @todo (Pablo - 2018-05-08) - Extract fields safely
            $oInput = Factory::service('Input');
            $aData  = $oInput->post();

            if (!$this->oModel->update($oItem->id, $aData)) {
                throw new ApiException('Failed to update resource. ' . $this->oModel->lastError());
            }

            return [];

        } catch (\Exception $e) {
            return [
                'status' => (int) $e->getCode() ?: 500,
                'error'  => $e->getMessage(),
            ];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing resource
     * @return array
     */
    public function deleteRemap()
    {
        try {

            $oItem = $this->lookUpResource();
            if (!$oItem) {
                throw new ApiException('Resource not found', 404);
            }

            //  @todo (Pablo - 2018-05-08) - Validate user can delete resource

            if (!$this->oModel->delete($oItem->id)) {
                throw new ApiException('Failed to delete resource. ' . $this->oModel->lastError());
            }

            return [];

        } catch (\Exception $e) {
            return [
                'status' => (int) $e->getCode() ?: 500,
                'error'  => $e->getMessage(),
            ];
        }
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
