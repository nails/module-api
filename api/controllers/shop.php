<?php

//  Include NAILS_API_Controller; executes common API functionality.
require_once '_api.php';

use Omnipay\Common;
use Omnipay\Omnipay;

/**
 * Shop API end points
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class NAILS_Shop extends NAILS_API_Controller
{
    /**
     * Constructor
     *
     * @access  public
     * @return  void
     *
     **/
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Check this module is enabled in settings
        if (!isModuleEnabled('shop')) {

            //  Cancel execution, module isn't enabled
            $this->methodNotFound($this->uri->segment(2));
        }

        // --------------------------------------------------------------------------

        $this->load->model('shop/shop_model');
    }


    // --------------------------------------------------------------------------


    public function basket()
    {
        $method = $this->uri->segment(4);

        if (method_exists($this, '_basket_' . $method)) {

            $this->{'_basket_' . $method}();

        } else {

            $this->methodNotFound('basket/' . $method);
        }
    }


    // --------------------------------------------------------------------------


    protected function _basket_add()
    {
        $out = array();

        // --------------------------------------------------------------------------

        $variantId = $this->input->get_post('variantId');
        $quantity  = $this->input->get_post('quantity') ? $this->input->get_post('quantity') : 1;

        if (!$this->shop_basket_model->add($variantId, $$quantity)) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_remove()
    {
        $out = array();

        // --------------------------------------------------------------------------

        $variantId = $this->input->get_post('variantId');

        if (!$this->shop_basket_model->remove($variantId)) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_increment()
    {
        $out = array();

        // --------------------------------------------------------------------------

        $variantId = $this->input->get_post('variantId');

        if (!$this->shop_basket_model->increment($variantId)) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_decrement()
    {
        $out = array();

        // --------------------------------------------------------------------------

        $variantId = $this->input->get_post('variantId');

        if (!$this->shop_basket_model->decrement($variantId)) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_add_voucher()
    {
        $out     = array();
        $voucher = $this->shop_voucher_model->validate($this->input->get_post('voucher'), get_basket());

        if ($voucher) {

            if (!$this->shop_basket_model->addVoucher($voucher->code)) {

                $out['status'] = 400;
                $out['error']  = $this->shop_basket_model->last_error();
            }

        } else {

            $out['status'] = 400;
            $out['error']  = $this->shop_voucher_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_remove_voucher()
    {
        $out = array();

        // --------------------------------------------------------------------------

        if (!$this->shop_basket_model->removeVoucher()) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    public function _basket_add_note()
    {
        $out  = array();
        $note = $this->input->get_post('note');

        // --------------------------------------------------------------------------

        if (!$this->shop_basket_model->addNote($note)) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }


    // --------------------------------------------------------------------------


    protected function _basket_set_currency()
    {
        $out      = array();
        $currency = $this->shop_currency_model->get_by_code($this->input->get_post('currency'));

        if ($currency) {

            $this->session->set_userdata('shop_currency', $currency->code);

            if ($this->user_model->is_logged_in()) {

                //  Save to the user object
                $this->user_model->update(active_user('id'), array('shop_currency' => $currency->code));
            }

        } else {

            $out['status'] = 400;
            $out['error']  = $this->shop_currency_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    public function _basket_set_as_collection()
    {
        $out = array();

        // --------------------------------------------------------------------------

        if (!$this->shop_basket_model->setDeliveryType('COLLECT')) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    public function _basket_set_as_delivery()
    {
        $out = array();

        // --------------------------------------------------------------------------

        if (!$this->shop_basket_model->setDeliveryType('DELIVER')) {

            $out['status'] = 400;
            $out['error']  = $this->shop_basket_model->last_error();
        }

        // --------------------------------------------------------------------------

        $this->_out($out);
    }

    // --------------------------------------------------------------------------

    public function webhook()
    {
        /**
         * We'll do logging for this method as it's reasonably important that
         * we keep a history of the things which happen
         */

        _LOG('Webhook initialising');
        _LOG('State:');
        _LOG('RAW GET Data: ' . $this->input->server('QUERY_STRING'));
        _LOG('RAW POST Data: ' . file_get_contents('php://input'));

        $out = array('status' => 200);

        // --------------------------------------------------------------------------

        $this->load->model('shop/shop_payment_gateway_model');
        $result = $this->shop_payment_gateway_model->webhook_complete_payment($this->uri->segment(4), true);

        if (!$result) {

            $out['status'] = 500;
            $out['error']  = $this->shop_payment_gateway_model->last_error();
        }

        // --------------------------------------------------------------------------

        _LOG('Webhook terminating');

        /**
         * Return in the format expected by the gateway, and don't set a header. Most
         * gateways will keep trying, or send false positive failures if this comes
         * back as non-200.
         */

        switch (strtolower($this->uri->segment(4))) {

            case 'worldpay':

                $format = 'TXT';
                $out    = json_encode($out);
                break;

            default:

                $format = 'JSON';
                break;

        }

        $this->_out($out, $format, false);
    }


    // --------------------------------------------------------------------------


    public function order()
    {
        $method = $this->uri->segment(4);

        if (method_exists($this, '_order_' . $method)) {

            $this->load->model('shop/shop_order_model');
            $this->{'_order_' . $method}();

        } else {

            $this->methodNotFound('order/' . $method);
        }
    }


    // --------------------------------------------------------------------------


    protected function _order_status()
    {
        $out   = array();
        $order = $this->shop_order_model->get_by_ref($this->input->get_post('ref'));

        if ($order) {

            $out['order']            = new stdClass();
            $out['order']->status    = $order->status;
            $out['order']->is_recent = (time() - strtotime($order->created)) < 300;

        } else {

            $out['status']  = 400;
            $out['error']   = '"' . $this->input->get_post('ref') . '" is not a valid order ref';
        }

        // --------------------------------------------------------------------------

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

if (!defined('NAILS_ALLOW_EXTENSION_SHOP')) {

    class Shop extends NAILS_Shop
    {
    }
}
