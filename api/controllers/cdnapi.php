<?php

//  Include NAILS_API_Controller; executes common API functionality.
require_once '_api.php';

/**
 * CDN API end points
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class NAILS_Cdnapi extends NAILS_API_Controller
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

        //  Check this module is enabled in settings
        if (!isModuleEnabled('nailsapp/module-cdn')) {

            //  Cancel execution, module isn't enabled
            $this->methodNotFound($this->uri->segment(2));
        }

        // --------------------------------------------------------------------------

        $this->load->library('cdn/cdn');
    }

    // --------------------------------------------------------------------------

    /**
     * Generate an upload token for the logged in user
     * @return void
     */
    public function get_upload_token()
    {
        //  Define $out array
        $out = array();

        // --------------------------------------------------------------------------

        if ($this->user_model->is_logged_in()) {

            $out['token'] = $this->cdn->generate_api_upload_token(active_user('id'));

        } else {

            $out['status'] = 400;
            $out['error']  = 'You must be logged in to generate an upload token.';
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Upload a new object to the CDN
     * @return void
     */
    public function object_create()
    {
        //  Define $out array
        $out = array();

        // --------------------------------------------------------------------------

        if (!$this->user_model->is_logged_in()) {

            //  User is not logged in must supply a valid upload token
            $token = $this->input->get_post('token');

            if (!$token) {

                //  Sent as a header?
                $token = $this->input->get_request_header('X-cdn-token');
            }

            $user = $this->cdn->validate_api_upload_token($token);

            if (!$user) {

                $out['status'] = 400;
                $out['error']  = $this->cdn->last_error();

                $this->_out($out);
                return;

            } else {

                $this->user_model->set_active_user($user);
            }
        }

        // --------------------------------------------------------------------------

        //  Uploader verified, bucket defined and valid?
        $bucket = $this->input->get_post('bucket');

        if (!$bucket) {

            //  Sent as a header?
            $bucket = $this->input->get_request_header('X-cdn-bucket');
        }

        if (!$bucket) {

            $out['status'] = 400;
            $out['error']  = 'Bucket not defined.';

            $this->_out($out);
            return;
        }

        // --------------------------------------------------------------------------

        //  Attempt upload
        $upload = $this->cdn->object_create('upload', $bucket);

        if ($upload) {

            //  Success!Return as per the user's preference
            $return = $this->input->post('return');

            if (!$return) {

                //  Sent as a header?
                $return = $this->input->get_request_header('X-cdn-return');
            }

            if ($return) {

                $format = explode('|', $return);

                switch (strtoupper($format[0])) {

                    //  URL
                    case 'URL' :

                        if (isset($format[1])) {

                            switch (strtoupper($format[1])) {

                                case 'THUMB':

                                    //  Generate a url for each request
                                    $out['object_url'] = array();
                                    $sizes             = explode(',', $format[2]);

                                    foreach ($sizes as $size) {

                                        $dimensions = explode('x', $size);

                                        $w = isset($dimensions[0]) ? $dimensions[0] : '';
                                        $h = isset($dimensions[1]) ? $dimensions[1] : '';

                                        $out['object_url'][] = cdn_thumb($upload->id, $w, $h);
                                    }

                                    $out['object_id']  = $upload->id;
                                    break;

                                case 'SCALE':

                                    //  Generate a url for each request
                                    $out['object_url'] = array();
                                    $sizes             = explode(',', $format[2]);

                                    foreach ($sizes as $size) {

                                        $dimensions = explode('x', $size);

                                        $w = isset($dimensions[0]) ? $dimensions[0] : '';
                                        $h = isset($dimensions[1]) ? $dimensions[1] : '';

                                        $out['object_url'][] = cdn_scale($upload->id, $w, $h);
                                    }

                                    $out['object_id']  = $upload->id;
                                    break;

                                case 'SERVE_DL':
                                case 'DOWNLOAD':
                                case 'SERVE_DOWNLOAD':

                                    $out['object_url'] = cdn_serve($upload->id, true);
                                    $out['object_id']  = $upload->id;
                                    break;

                                case 'SERVE':
                                default:

                                    $out['object_url'] = cdn_serve($upload->id);
                                    $out['object_id']  = $upload->id;
                                    break;
                            }

                        } else {

                            //  Unknow, return the serve URL & ID
                            $out['object_url'] = cdn_serve($upload->id);
                            $out['object_id']  = $upload->id;
                        }
                        break;

                    default:

                        //  just return the object
                        $out['object'] = $upload;
                        break;
                }

            } else {

                //  just return the object
                $out['object'] = $upload;
            }

        } else {

            $out['status'] = 400;
            $out['error']  = $this->cdn->last_error();
        }

        // --------------------------------------------------------------------------

        /**
         * Make sure the _out() method doesn't send a header, annoyingly SWFupload does
         * not return the server response to the script when a non-200 status code is
         * detected
         */

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Delete an object from the CDN
     * @return void
     */
    public function object_delete()
    {
        /**
         * @TODO: Have a good think about security here, somehow verify that this
         * person has permission to delete objects. Perhaps only an objects creator
         * or a super user can delete. Maybe have a CDN permission?
         */

        //  Define $out array
        $out = array();

        // --------------------------------------------------------------------------

        $objectId = $this->input->get_post('object_id');
        $delete   = $this->cdn->object_delete($objectId);

        if (!$delete) {

            $out['status'] = 400;
            $out['error']  = $this->cdn->last_error();
        }

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Add an object to a tag
     * @return void
     */
    public function add_object_tag()
    {
        $objectId = $this->input->get('object_id');
        $tagId    = $this->input->get('tag_id');
        $out      = array();

        $added = $this->cdn->object_tag_add($objectId, $tagId);

        if ($added) {

            //  Get new count for this tag
            $out = array(
                'new_total' => $this->cdn->object_tag_count($tagId)
           );

        } else {

            $out = array(
                'status' => 400,
                'error'  => $this->cdn->last_error()
           );
        }

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Remove an object from a tag
     * @return void
     */
    public function delete_object_tag()
    {
        $objectId = $this->input->get('object_id');
        $tagId    = $this->input->get('tag_id');
        $out      = array();

        $deleted = $this->cdn->object_tag_delete($objectId, $tagId);

        if ($deleted) {

            //  Get new count for this tag
            $out = array(
                'new_total' => $this->cdn->object_tag_count($tagId)
           );

        } else {

            $out = array(
                'status' => 400,
                'error'  => $this->cdn->last_error()
           );
        }

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

if (!defined('NAILS_ALLOW_EXTENSION_CDNAPI')) {

    class Cdnapi extends NAILS_Cdnapi
    {
    }
}
