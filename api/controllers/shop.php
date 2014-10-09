<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Name:		Shop API
 *
 * Description:	This controller handles Shop API methods
 *
 **/

require_once '_api.php';

/**
 * OVERLOADING NAILS' API MODULES
 *
 * Note the name of this class; done like this to allow apps to extend this class.
 * Read full explanation at the bottom of this file.
 *
 **/

use Omnipay\Common;
use Omnipay\Omnipay;

class NAILS_Shop extends NAILS_API_Controller
{
	protected $_authorised;
	protected $_error;


	// --------------------------------------------------------------------------


	/**
	 * Constructor
	 *
	 * @access	public
	 * @return	void
	 *
	 **/
	public function __construct()
	{
		parent::__construct();

		// --------------------------------------------------------------------------

		//	Check this module is enabled in settings
		if ( ! module_is_enabled( 'shop' ) ) :

			//	Cancel execution, module isn't enabled
			$this->_method_not_found( $this->uri->segment( 2 ) );

		endif;

		// --------------------------------------------------------------------------

		$this->load->model( 'shop/shop_model' );
	}


	// --------------------------------------------------------------------------


	public function basket()
	{
		$_method = $this->uri->segment( 4 );

		if ( method_exists( $this, '_basket_' . $_method ) ) :

			$this->{'_basket_' . $_method}();

		else :

			$this->_method_not_found( 'basket/' . $_method );

		endif;
	}


	// --------------------------------------------------------------------------


	protected function _basket_add()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		$_variant_id	= $this->input->get_post( 'variant_id' );
		$_quantity		= $this->input->get_post( 'quantity' ) ? $this->input->get_post( 'quantity' ) : 1;

		if ( ! $this->shop_basket_model->add( $_variant_id, $$_quantity ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_remove()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		$_variant_id = $this->input->get_post( 'variant_id' );

		if ( ! $this->shop_basket_model->remove( $_variant_id ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_increment()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		$_variant_id = $this->input->get_post( 'variant_id' );

		if ( ! $this->shop_basket_model->increment( $_variant_id ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_decrement()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		$_variant_id = $this->input->get_post( 'variant_id' );

		if ( ! $this->shop_basket_model->decrement( $_variant_id ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_add_voucher()
	{
		$_out		= array();
		$_voucher	= $this->shop_voucher_model->validate( $this->input->get_post( 'voucher' ), get_basket() );

		if ( $_voucher ) :

			if ( ! $this->shop_basket_model->add_voucher( $_voucher->code ) ) :

				$_out['status']	= 400;
				$_out['error']	= $this->shop_basket_model->last_error();

			endif;

		else :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_voucher_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_remove_voucher()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->remove_voucher() ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_set_currency()
	{
		$_out		= array();
		$_currency	= $this->shop_currency_model->get_by_code( $this->input->get_post( 'currency' ) );

		if ( $_currency ) :

			$this->session->set_userdata( 'shop_currency', $_currency->code );

			if ( $this->user_model->is_logged_in() ) :

				//	Save to the user object
				$this->user_model->update( active_user( 'id' ), array( 'shop_currency' => $_currency->code ) );

			endif;

		else :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_currency_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	public function webhook()
	{
		/**
		 * We'll do logging for this method as it's reasonably important that
		 * we keep a history of the things which happen
		 */

		// _LOG_MUTE_OUTPUT( TRUE );
		_LOG( 'Webhook initialising' );
		_LOG( 'State:' );
		_LOG( 'RAW GET Data: ' . $this->input->server( 'QUERY_STRING' ) );
		_LOG( 'RAW POST Data: ' . file_get_contents( 'php://input' ) );

		$_out = array();

		// --------------------------------------------------------------------------

		$this->load->model( 'shop/shop_payment_gateway_model' );
		$_result = $this->shop_payment_gateway_model->webhook_complete_payment( $this->uri->segment( 4 ), TRUE );

		if ( ! $_result ) :

			$_out['status'] = 500;
			$_out['error']	= $this->shop_payment_gateway_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		_LOG( 'Webhook terminating' );

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	public function order()
	{
		$_method = $this->uri->segment( 4 );

		if ( method_exists( $this, '_order_' . $_method ) ) :

			$this->load->model( 'shop/shop_order_model' );
			$this->{'_order_' . $_method}();

		else :

			$this->_method_not_found( 'order/' . $_method );

		endif;
	}


	// --------------------------------------------------------------------------


	protected function _order_status()
	{
		$_out	= array();
		$_order	= $this->shop_order_model->get_by_ref( $this->input->get_post( 'ref' ) );

		if ( $_order ) :

			$_out['order']				= new stdClass();
			$_out['order']->status		= $_order->status;
			$_out['order']->is_recent	= ( time() - strtotime( $_order->created ) ) < 300;

		else :

			$_out['status']	= 400;
			$_out['error']	= '"' . $this->input->get_post( 'ref' ) . '" is not a valid order ref';

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
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

if ( ! defined( 'NAILS_ALLOW_EXTENSION_SHOP' ) ) :

	class Shop extends NAILS_Shop
	{
	}

endif;

/* End of file shop.php */
/* Location: ./modules/api/controllers/shop.php */