<?php

//  Include NAILS_API_Controller; executes common API functionality.
require_once '_api.php';

/**
 * Auth API end points
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class NAILS_Auth extends NAILS_API_Controller
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

        //  Where are we returning user to?
        $this->data['return_to'] = $this->input->get('return_to');
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies and logs a user in
     * @return void
     * @todo Handle MFA
     */
    public function login()
    {
        $email    = $this->input->post('email');
        $password = $this->input->post('password');
        $remember = $this->input->post('remember');
        $user     = $this->auth_model->login($email, $password, $remember);
        $out      = array();

        if ($user) {

            /**
             * User was recognised and permitted to log in. Final check to
             * determine whether they are using a temporary password or not.
             *
             * $login will be an array containing the keys first_name, last_login, homepage;
             * the key temp_pw will be present if they are using a temporary password.
             *
             **/

            if (!empty($user->temp_pw)) {

                /**
                 * Temporary password detected, log user out and redirect to
                 * temp password reset page.
                 *
                 **/

                $returnTo = $this->data['return_to'] ? '?return_to='.urlencode($this->data['return_to']) : null;

                $this->auth_model->logout();

                $out['status'] = 401;
                $out['error']  = 'Temporary Password';
                $out['code']   = 2;
                $out['goto']   = site_url('auth/reset_password/' . $user->id . '/' . md5($user->salt) . $returnTo);

            } else {

                //  Finally! Work out where the user should go next
                $goTo = $this->data['return_to'] ? $this->data['return_to'] : $user->group_homepage;

                // --------------------------------------------------------------------------

                //  Generate an event for this log in
                create_event('did_log_in', array('method' => 'api'), $user->id);

                // --------------------------------------------------------------------------

                //  Login failed
                $out['goto'] = site_url($goTo);
            }

        } else {

            //  Login failed
            $out['status'] = 401;
            $out['error']  = $this->auth_model->last_error();
            $out['code']   = 1;
        }

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user out
     * @return void
     */
    public function logout()
    {
        //  Only create the event if the user is logged in
        if ($this->user_model->isLoggedIn()) {

            //  Generate an event for this log in
            create_event('did_log_out');

            // --------------------------------------------------------------------------

            //  Log user out
            $this->auth_model->logout();
        }

        // --------------------------------------------------------------------------

        $this->_out();
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

if (!defined('NAILS_ALLOW_EXTENSION_AUTH')) {

    class Auth extends NAILS_Auth
    {
    }

}
