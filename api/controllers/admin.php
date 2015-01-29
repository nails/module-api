<?php

//  Include NAILS_API_Controller; executes common API functionality.
require_once '_api.php';

/**
 * Admin API end points
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class NAILS_Admin extends NAILS_API_Controller
{
    private $_authorised;
    private $_error;

    // --------------------------------------------------------------------------

    /**
     * Constructor
     **/
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        $this->_authorised = true;
        $this->_error      = '';

        // --------------------------------------------------------------------------

        //  Constructor mabobs.

        //  IP whitelist?
        $whitelistIp = (array) app_setting('whitelist', 'admin');

        if ($whitelistIp) {

            if (!isIpInRange($this->input->ip_address(), $whitelistIp)) {

                show_404();
            }
        }

        //  Only logged in users
        if (!$this->user_model->is_logged_in()) {

            $this->_authorised = false;
            $this->_error      = lang('auth_require_session');

        //  Only admins
        } elseif (!$this->user_model->is_admin()) {

            $this->_authorised = false;
            $this->_error      = lang('auth_require_admin');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Routes requests to nav/*
     * @return void
     */
    public function nav()
    {
        if (!$this->_authorised) {

            $_out           = array();
            $_out['status'] = 401;
            $_out['error']  = $this->_error;
            $this->_out($_out);
            return;
        }

        // --------------------------------------------------------------------------

        $methodRaw = $this->uri->segment(4) ? $this->uri->segment(4) : 'index';
        $method    = 'nav' . underscore_to_camelcase($methodRaw, false);

        if (method_exists($this, $method)) {

            $this->{$method}();

        } else {

            $this->methodNotFound('nav/' . $methodRaw);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Saves the user's admin nav preferences
     * @return void
     */
    protected function navSave()
    {
        $prefRaw = $this->input->get_post('preferences');
        $pref    = new stdClass();

        foreach ($prefRaw as $module => $options) {

            $pref->{$module}       = new stdClass();
            $pref->{$module}->open = stringToBoolean($options['open']);
        }

        $this->load->model('admin/admin_model');
        $this->admin_model->setAdminData('nav', $pref);

        $this->_out();
    }

    // --------------------------------------------------------------------------

    /**
     * Resets a user's admin nav preferences
     * @return void
     */
    protected function navReset()
    {
        $this->load->model('admin/admin_model');
        $this->admin_model->unsetAdminData('nav');

        $this->_out();
    }

    // --------------------------------------------------------------------------

    /**
     * Routes requests to users/*
     * @return void
     */
    public function users()
    {
        if (!$this->_authorised) {

            $_out           = array();
            $_out['status'] = 401;
            $_out['error']  = $this->_error;
            $this->_out($_out);
            return;
        }

        // --------------------------------------------------------------------------

        $methodRaw = $this->uri->segment(4) ? $this->uri->segment(4) : 'index';
        $method    = 'users' . underscore_to_camelcase($methodRaw, false);

        if (method_exists($this, $method)) {

            $this->{$method}();

        } else {

            $this->methodNotFound('users/' . $methodRaw);

        }
    }

    // --------------------------------------------------------------------------

    /**
     * Searches users
     * @return void
     */
    protected function usersSearch()
    {
        $avatarSize = $this->input->get('avatarSize') ? $this->input->get('avatarSize') : 50;
        $term       = $this->input->get('term');
        $users      = $this->user_model->get_all(null, null, null, null, $term);
        $out        = array('users' => array());

        foreach ($users as $user) {

            $temp              = new stdClass();
            $temp->id          = $user->id;
            $temp->email       = $user->email;
            $temp->first_name  = $user->first_name;
            $temp->last_name   = $user->last_name;
            $temp->gender      = $user->gender;
            $temp->profile_img = cdn_avatar($temp->id, $avatarSize, $avatarSize);

            $out['users'][] = $temp;
        }

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Routes requests to shop/*
     * @return void
     */
    public function shop()
    {
        if (!$this->_authorised) {

            $_out           = array();
            $_out['status'] = 401;
            $_out['error']  = $this->_error;
            $this->_out($_out);
            return;
        }

        // --------------------------------------------------------------------------

        $methodRaw = $this->uri->segment(4) ? $this->uri->segment(4) : 'index';
        $method    = 'shop' . underscore_to_camelcase($methodRaw, false);

        if (method_exists($this, $method)) {

            $this->{$method}();

        } else {

            $this->methodNotFound('users/' . $methodRaw);

        }
    }

    // --------------------------------------------------------------------------

    protected function shopVoucherGenerateCode()
    {
        $out = array();

        $this->load->model('shop/shop_voucher_model');

        $out['code'] = $this->shop_voucher_model->generateValidCode();
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

if (!defined('NAILS_ALLOW_EXTENSION_ADMIN')) {

    class Admin extends NAILS_Admin
    {
    }
}
