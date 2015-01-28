<?php

//  Include NAILS_API_Controller; executes common API functionality.
require_once '_api.php';

/**
 * CMS API end points
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */
class NAILS_Cms extends NAILS_API_Controller
{
    private $_authorised;
    private $_error;

    // --------------------------------------------------------------------------

    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        $this->_authorised  = true;
        $this->_error       = '';

        // --------------------------------------------------------------------------

        if (!isModuleEnabled('cms')) {

            //  Cancel execution, module isn't enabled
            show_404();
        }

        // --------------------------------------------------------------------------

        //  Only logged in users
        if (!$this->user_model->is_logged_in()) {

            $this->_authorised = false;
            $this->_error      = lang('auth_require_session');
        }

        // --------------------------------------------------------------------------

        //  Only admins
        if (!$this->user_model->is_admin()) {

            $this->_authorised  = false;
            $this->_error       = lang('auth_require_admin');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Route requests to the pages endpoints
     * @return void
     */
    public function pages()
    {
        if (!$this->_authorised) {

            $this->_out(array('status' => 401, 'error' => $this->_error));
            return;
        }

        // --------------------------------------------------------------------------

        $this->load->helper('string');
        $method = 'pages' . underscore_to_camelcase(strtolower($this->uri->segment(4)));

        if (method_exists($this, $method)) {

            return $this->$method();

        } else {

            $this->methodNotFound($this->uri->segment(4));
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Routes request to the pages/widget endpoints
     * @return void
     */
    protected function pagesWidget()
    {
        $this->load->helper('string');
        $method = 'pagesWidget' . underscore_to_camelcase(strtolower($this->uri->segment(5)));

        if (method_exists($this, $method)) {

            return $this->$method();

        } else {

            $this->methodNotFound($this->uri->segment(5));
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Get CMS Widget editor HTML
     * @return void
     */
    protected function pagesWidgetGetEditor()
    {
        $out             = array();
        $requestedWidget = $this->input->get_post('widget');

        parse_str($this->input->get_post('data'), $widgetData);

        if ($requestedWidget) {

            $this->load->model('cms/cms_page_model');

            $requestedWidget = $this->cms_page_model->get_widget($requestedWidget);

            if ($requestedWidget) {

                //  Instantiate the widget
                include_once $requestedWidget->path . 'widget.php';

                try {

                    $WIDGET       = new $requestedWidget->iam();
                    $widgetEditor = $WIDGET->get_editor($widgetData);

                    if (!empty($widgetEditor)) {

                        $out['HTML'] = $widgetEditor;

                    } else {

                        $out['HTML'] = '<p class="static">This widget has no configurable options.</p>';
                    }

                } catch (Exception $e) {

                    $out['status'] = 500;
                    $out['error']  = 'This widget has not been configured correctly. Please contact the developer ';
                    $out['error'] .= 'quoting this error message: ';
                    $out['error'] .= '<strong>"#3:' . $requestedWidget->iam . ':GetEditor"</strong>';
                }

            } else {

                $out['status'] = 400;
                $out['error']  = 'Invalid Widget - Error number 2';
            }

        } else {

            $out['status'] = 400;
            $out['error']  = 'Widget slug must be specified - Error number 1';
        }

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Save a CMS Page
     * @return void
     */
    protected function pagesSave()
    {
        $pageDataRaw     = $this->input->get_post('page_data');
        $publishAction   = $this->input->get_post('publish_action');
        $generatePreview = $this->input->get_post('generate_preview');

        if (!$pageDataRaw) {

            $this->_out(array(
                'status' => 400,
                'error'  => '"page_data" is a required parameter.'
           ));
            return;
        }

        // --------------------------------------------------------------------------

        //  Decode and check
        $pageData = json_decode($pageDataRaw);

        if (is_null($pageData)) {

            $this->_out(array(
                'status' => 400,
                'error'  => '"page_data" is a required parameter.'
            ));
            log_message('error', 'API: cms/pages/save - Error decoding JSON: ' . $pageDataRaw);
            return;
        }

        if (empty($pageData->hash)) {

            $this->_out(array(
                'status' => 400,
                'error'  => '"hash" is a required parameter.'
           ));
            log_message('error', 'API: cms/pages/save - Empty hash supplied.');
            return;
        }

        //  A template must be defined
        if (empty($pageData->data->template)) {

            $this->_out(array(
                'status' => 400,
                'error'  => '"data.template" is a required parameter.'
           ));
            return;
        }

        // --------------------------------------------------------------------------

        /**
         * Validate data
         * JSON.stringify doesn't seem to escape forward slashes like PHP does. Check
         * both in case this is a cross browser issue.
         */

        $hash                   = $pageData->hash;
        $checkObj               = new stdClass();
        $checkObj->data         = $pageData->data;
        $checkObj->widget_areas = $pageData->widget_areas;
        $checkHash1             = md5(json_encode($checkObj, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        if ($hash !== $checkHash1) {

            $checkHash2 = md5(json_encode($checkObj));

            if ($hash !== $checkHash2) {

                $this->_out(array(
                    'status' => 400,
                    'error'  => 'Data failed hash validation. Data might have been modified in transit.'
                ));
                log_message('error', 'API: cms/pages/save - Failed to verify hashes. Posted JSON{ ' .  $pageDataRaw );
                return;
            }
        }

        $pageData->hash = $hash;

        // --------------------------------------------------------------------------

        /**
         * All seems good, let's process this mofo'ing data. Same format as supplied,
         * just manually specifying things for supreme consistency. Multi-pass?
         */

        $data                          = new stdClass();
        $data->hash                    = $pageData->hash;
        $data->id                      = !empty($pageData->id) ? (int) $pageData->id : null;
        $data->data                    = new stdClass();
        $data->data->title             = !empty($pageData->data->title) ? $pageData->data->title : '';
        $data->data->parent_id         = !empty($pageData->data->parent_id) ? (int) $pageData->data->parent_id : '';
        $data->data->seo_title         = !empty($pageData->data->seo_title) ? $pageData->data->seo_title : '';
        $data->data->seo_description   = !empty($pageData->data->seo_description) ? $pageData->data->seo_description : '';
        $data->data->seo_keywords      = !empty($pageData->data->seo_keywords) ? $pageData->data->seo_keywords : '';
        $data->data->template          = $pageData->data->template;
        $data->data->additional_fields = !empty($pageData->data->additional_fields) ? $pageData->data->additional_fields : '';
        $data->widget_areas            = !empty($pageData->widget_areas) ? $pageData->widget_areas : new stdClass;

        if ($data->data->additional_fields) {

            parse_str($data->data->additional_fields, $_additional_fields);

            if (!empty($_additional_fields['additional_field'])) {

                $data->data->additional_fields = $_additional_fields['additional_field'];

            } else {

                $data->data->additional_fields = array();
            }

            /**
             * We're going to encode then decode the additional fields, so they're
             * consistent with the save objects
             */

            $data->data->additional_fields = json_decode(json_encode($data->data->additional_fields));
        }

        // --------------------------------------------------------------------------

        /**
         * Data is set, determine whether we're previewing, saving or creating
         * If an ID is missing then we're creating a new page otherwise we're updating.
         */

        $this->load->model('cms/cms_page_model');

        if (!empty($generatePreview)) {

            if (!user_has_permission('admin.cms:0.can_preview_page')) {

                $this->_out(array(
                    'status' => 400,
                    'error'  => 'You do not have permission to preview CMS Pages.'
               ));
                return;
            }

            $id = $this->cms_page_model->create_preview($data);

            if (!$id) {

                $this->_out(array(
                    'status' => 500,
                    'error'  => 'There was a problem creating the page preview. ' . $this->cms_page_model->last_error()
               ));
                return;
            }

            $out       = array();
            $out['id'] = $id;

        } else {

            if (!$data->id) {

                if (!user_has_permission('admin.cms:0.can_create_page')) {

                    $this->_out(array(
                        'status' => 400,
                        'error'  => 'You do not have permission to create CMS Pages.'
                   ));
                    return;
                }

                $id = $this->cms_page_model->create($data);

                if (!$id) {

                    $this->_out(array(
                        'status' => 500,
                        'error'  => 'There was a problem saving the page. ' . $this->cms_page_model->last_error()
                   ));
                    return;
                }

            } else {

                if (!user_has_permission('admin.cms:0.can_edit_page')) {

                    $this->_out(array(
                        'status' => 400,
                        'error'  => 'You do not have permission to edit CMS Pages.'
                   ));
                    return;

                }

                if ($this->cms_page_model->update($data->id, $data, $this->data)) {

                    $id = $data->id;

                } else {

                    $this->_out(array(
                        'status' => 500,
                        'error'  => 'There was a problem saving the page. ' . $this->cms_page_model->last_error()
                   ));
                    return;
                }
            }

            // --------------------------------------------------------------------------

            /**
             * Page has been saved! Any further steps?
             * - If is_published is defined then we need to consider it's published status.
             * - If is_published is null then we're leaving it as it is.
             */

            $out       = array();
            $out['id'] = $id;

            switch ($publishAction) {

                case 'PUBLISH':

                    $this->cms_page_model->publish($id);
                    break;

                case 'NONE':
                default:

                    //  Do nothing, absolutely nothing. Go have a margarita.
                    break;
            }
        }

        // --------------------------------------------------------------------------

        //  Return
        $this->_out($out);
    }
}

// --------------------------------------------------------------------------

/**
 * OVERLOADING NAILS' API MODULES
 *
 * The following block of code makes it simple to extend one of the core API
 * controllers. Some might argue it's a little hacky but it's a simple 'fix'
 * which negates the need to massively extend the CodeIgniter Loader class
 * even further (in all honesty I just can't face understanding the whole
 * Loader class well enough to change it 'properly').
 *
 * Here's how it works:
 *
 * CodeIgniter instantiate a class with the same name as the file, therefore
 * when we try to extend the parent class we get 'cannot redeclare class X' errors
 * and if we call our overloading class something else it will never get instantiated.
 *
 * We solve this by prefixing the main class with NAILS_ and then conditionally
 * declaring this helper class below; the helper gets instantiated et voila.
 *
 * If/when we want to extend the main class we simply define NAILS_ALLOW_EXTENSION_CLASSNAME
 * before including this PHP file and extend as normal (i.e in the same way as below);
 * the helper won't be declared so we can declare our own one, app specific.
 *
 **/

if (!defined('NAILS_ALLOW_EXTENSION_CMS')) {

    class Cms extends NAILS_Cms
    {
    }
}
