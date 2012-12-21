<?php
/**
 * Mijireh
 *
 * @author Aaron (adwb) Bronow
 * @version 0.1
 * @copyright n/a
 * @package Shopp
 * @since 1.2.1
 * @subpackage Mijireh
 *
 **/

class Mijireh extends GatewayFramework {

	var $secure = false;
	var $refunds = false;
	var $captures = false;
	var $cards = array();

	function __construct () {
		parent::__construct();
		
		$this->setup('cards','error');

		// Autoset useable payment cards
		$this->settings['cards'] = array();
		foreach ($this->cards as $card)	$this->settings['cards'][] = $card->symbol;

    // add_filter('shopp_checkout_submit_button',array(&$this,'checkout_buttons_filter'),10,3);
    // add_filter('shopp_checkout_form',array(&$this,'checkout_form_filter'));
    add_filter('shopp_billing_address_required', array(&$this,'override_billing_address_requirement'),10,3);
    add_filter('shopp_checkout_confirm_button', array(&$this,'confirm_button_filter'), 10, 3);
    
    // add_action('shopp_mijireh_sale',array(&$this,'sale'));
    // add_action('shopp_mijireh_auth',array(&$this,'auth'));
    // add_action('shopp_mijireh_capture',array(&$this,'capture'));
    // add_action('shopp_mijireh_refund',array(&$this,'refund'));
    // add_action('shopp_mijireh_void',array(&$this,'void'));
		add_action('shopp_remote_payment', array($this, 'returned'));
    // add_action('shopp_init_confirmation',array(&$this, 'init_confirm'));
	}

	function returned () {
    $orderNumber = $_GET['order_number'];
    
		if (empty($orderNumber)) {
			new ShoppError(__('The order submitted by Mijireh did not specify a transaction ID.','Shopp'),'mj_validation_error',SHOPP_TRXN_ERR);
			shopp_redirect(shoppurl(false,'checkout'));
		}
		
    // $mijirehOrderData = $this->get_order($orderNumber);
		
    // $total = $mijirehOrderData->total;
		
		$total = $this->Order->Cart->Totals->total;
		
    // TODO adwb: investigate event type 'purchase' and figure out which shopp type it should be
		$purchase = shopp_add_order_event(false, 'purchase', array(
			'gateway' => $this->Order->processor(),
			'txnid' => $orderNumber
		));

    shopp_add_order_event(ShoppPurchase()->id, 'authed', array( 
        'txnid' => $orderNumber,             // Transaction ID from payment gateway, in some cases will be in $Event->txnid
        'amount' => $total,               // Gross amount authorized
        'gateway' => $Event->gateway,   // Gateway handler name (module name from @subpackage)
        'paymethod' => 'Credit Card',   // Payment method (payment method label from your payment settings)
        'paytype' => '',            // Type of payment (check, MasterCard, etc)
        'payid' => '',
        'capture' => true               // Shopp will automatically issue the captured event following authed
    ));
		
    // shopp_add_order_event(ShoppPurchase()->id, 'process', array(
    //   'gateway' => $this->Order->processor(),
    //   'amount' => $total
    // ));

    // ShoppOrder()->purchase = ShoppPurchase()->id;
    // shopp_redirect(shoppurl(false, 'thanks', false));
	}

  // TODO adwb: do we still need this?
	function checkout_buttons_filter ($tag=false,$options=array(),$attrs=array()) {
		$tag['mijireh'] = '<input type="submit" name="process" value="foo bar"/>';
		return $tag;
	}
	
	// TODO adwb: can we skip the confirmation page since Mijireh basically does the same thing?
  function confirm_button_filter( $markup, $options, $allowed ) {
    // TODO adwb: retrieve order number from mijireh or from internal cache then set input button to redirect
    $response = $this->create_new_order();
    $urlStart = strrpos($response,'checkout_url') + 16;
    $checkout_url = substr($response, $urlStart);
    $urlLength = strrpos($checkout_url,'",');
    $checkout_url = substr($response, $urlStart, $urlLength);
    $order = json_encode($this->Order->Cart->Totals->total);
    
    $return_url = add_query_arg('rmtpay','process',shoppurl(false,'thanks',false));
    return "<textarea rows='10' cols='20'>$return_url $response</textarea><a href='$checkout_url'>Confirm</a>";
  }
  
  // NOTE adwb: asks mijireh to create a new order using REST API and returns the JSON data
  // TODO adwb: parameterize the mijireh api token and move the cart contents loop out of here
  function create_new_order () {
    $url = 'https://secure.mijireh.com/api/1/orders';
    $return_url = add_query_arg('rmtpay','process',shoppurl(false,'thanks',false));
    $values = array(
      'total'=>$this->Order->Cart->Totals->total,
      'shipping'=>$this->Order->Cart->Totals->shipping,
      'tax'=>$this->Order->Cart->Totals->tax,
      'return_url'=>$return_url,
      'items'=> array(
      )
    );
    
    foreach ($this->Order->Cart->contents as $item) {
      array_push($values['items'], array( 'name'=>$item->name,'price'=>$item->unitprice));     
    }
    
    $payload = json_encode($values);
    // $data = $payload;
    $curl = curl_init();
    $timeout = 5;
    curl_setopt($curl,CURLOPT_URL,$url);
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,$timeout);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_USERPWD, "bb11f2645610977a71085fe0:");
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl,CURLOPT_POST,1);
    curl_setopt($curl,CURLOPT_POSTFIELDS,$payload);
    $data = curl_exec($curl);
    curl_close($curl);
    
    return $data;
  }
  
  function get_order ($orderNumber) {
    $url = "https://secure.mijireh.com/api/1/orders/{$orderNumber}";
    
    $curl = curl_init();
    $timeout = 5;
    curl_setopt($curl,CURLOPT_URL,$url);
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,$timeout);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_USERPWD, "bb11f2645610977a71085fe0:");
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    $data = curl_exec($curl);
    curl_close($curl);
    
    return $data;
  }
	
	function override_billing_address_requirement( $error ) {
      // Never allow an error on this field
      return false;
  }
	
	function refund (OrderEventMessage $Event) {
		$this->handler('refunded',$Event);
	}

	function void (OrderEventMessage $Event) {
		$this->handler('voided',$Event);
	}

	function handler ($type,$Event) {
		if(!isset($Event->txnid)) $Event->txnid = time();
		if (str_true($this->settings['error'])) {
			new ShoppError(__("This is an example error message. Disable the 'always show an error' setting to stop displaying this error.",'Shopp'),'testmode_error',SHOPP_TRXN_ERR);
			return shopp_add_order_event($Event->order,$Event->type.'-fail',array(
				'amount' => $Event->amount,
				'error' => 0,
				'message' => __("This is an example error message. Disable the 'always show an error' setting to stop displaying this error.",'Shopp'),
				'gateway' => $this->module
			));
		}

		shopp_add_order_event($Event->order,$type,array(
			'txnid' => $Event->txnid,
			'txnorigin' => $Event->txnid,
			'fees' => 0,
			'paymethod' => '',
			'payid' => '',
			'paytype' => '',
			'payid' => '1111',
			'amount' => $Event->amount,
			'gateway' => $this->module
		));
	}

	/**
	 * Render the settings for this gateway
	 *
	 * Uses ModuleSettingsUI to generate a JavaScript/jQuery based settings
	 * panel.
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function settings () {
		$this->ui->checkbox(0,array(
			'name' => 'error',
			'label' => 'Always show an error',
			'checked' => $this->settings['error']
		));
		
		$this->ui->text(0,array(
		  'name' => 'key',
		  'size' => 32,
		  'value' => $this->settings['key'],
		  'label' => __('Access Key')
		));
		
	}

} // END class Mijireh

?>