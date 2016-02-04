<?php
/*
Plugin Name: Atom Payment Gateway
Plugin URI: http://atomtech.in/
Description: Extends WooCommerce by Adding the Atom Paynetz Gateway.
Version: 1.0
Author: Atom
Author URI: http://atomtech.in/
*/
 
// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action( 'plugins_loaded', 'woocommerce_atom_init', 0 );
define('IMGDIR', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/img/');

function woocommerce_atom_init() {
    // If the parent WC_Payment_Gateway class doesn't exist
    // it means WooCommerce is not installed on the site
    // so do nothing
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return; 
    // If we made it this far, then include our Gateway Class
    class WC_Gateway_Atom extends WC_Payment_Gateway {
		
    // Setup our Gateway's id, description and other values
    function __construct() {
		global $woocommerce;
		global $wpdb;
		$this->id = "atom";
		$this->icon = IMGDIR . 'logo.png';
        $this->method_title = __( "Atom Payment Gateway", 'wc_gateway_atom' );
        $this->method_description = "Atom Gateway setting page.";
        $this->title = __( "Atom Payment Gateway", 'wc_gateway_atom' );
		$this->has_fields = false;
		$this->init_form_fields();
		$this->init_settings();
		$this->url 				= $this->settings['atom_domain'];
		$this->atom_port		= $this->settings['atom_port'];
		$this->login_id 		= $this->settings['login_id'];
		$this->password 		= $this->settings['password'];
		$this->description 		= $this->settings['description'];
		$this->atom_product_id  = $this->settings['atom_prod_id'];


        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		if(isset($woocommerce->cart)){
			$checkout_url = $woocommerce->cart->get_checkout_url();
			$this->notify_url = $checkout_url;
		}
		$this->check_atom_response();
		
		add_filter('cron_schedules', 'new_interval');

		// add once 30 minute interval to wp schedules
		function new_interval($interval) {

			$interval['minutes_30'] = array('interval' => 30*60, 'display' => 'Once 30 minutes');

			return $interval;
		}
		
		function InitiateMyCron() {
			wp_schedule_event(time(), 'minutes_30', 'update_ransaction_status');
		}
		
		//$this->wc_suc_unpaid_orders();
	}
	
	public function update_ransaction_status() {
		global $wpdb;
		$held_duration = get_option( 'woocommerce_hold_stock_minutes' );

		if ( $held_duration < 1 || get_option( 'woocommerce_manage_stock' ) != 'yes' )
			return;

		$date = date( "Y-m-d H:i:s", strtotime( '-' . absint( 0 ) . ' MINUTES', current_time( 'timestamp' ) ) );

		$unpaid_orders = $wpdb->get_results( $wpdb->prepare( "
			SELECT posts.ID, postmeta.meta_key, postmeta.meta_value, posts.post_modified
			FROM {$wpdb->posts} AS posts
			RIGHT JOIN {$wpdb->postmeta} AS postmeta ON posts.id=postmeta.post_id
			WHERE 	posts.post_type   IN ('" . implode( "','", wc_get_order_types() ) . "')
			AND 	posts.post_status = 'wc-pending'
			AND 	posts.post_modified + INTERVAL 10 MINUTE < %s
		", $date ) );
		
		$pending_array='';
		foreach($unpaid_orders as $value){
			if($value->meta_key=='_order_total'){
				$pending_array[]=$value;
			}
		}

		if(!empty($pending_array)){
			$response_URL 	=	"https://paynetzuat.atomtech.in/paynetz/vfts";
			$this->atom_port		= $this->settings['atom_port'];
			foreach($pending_array as $val){
				$mer_txn=$val->ID;
				$amt=$val->meta_value;
				$date = date("Y-m-d", strtotime($val->post_modified));
				$merchant_id=160;
			
			 	$param = "?merchantid=".$merchant_id."&merchanttxnid=".$mer_txn."&amt=".$amt."&tdate=".$date;
				
				$ch = curl_init();
				$useragent = 'woo-commerce plugin';
				curl_setopt($ch, CURLOPT_URL, $response_URL);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_PORT , 443);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
				curl_setopt($ch, CURLOPT_USERAGENT, $useragent);;
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				//information received from gateway is stored in $response.
				$response = curl_exec($ch);
				
				if(curl_errno($ch))
				{	
					echo '<div class="woocommerce-error">Curl error: "'. curl_error($ch).". Error in gateway credentials.</div>";
					die;
				}
				curl_close($ch);
				
				$parser = xml_parser_create('');
				xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
				xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
				xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
				xml_parse_into_struct($parser, trim($response), $xml_values);
				xml_parser_free($parser);
				$result_resp=$xml_values[0]['attributes']['VERIFIED'];
				
				$unpaid_order=$mer_txn;
				if ( $unpaid_order ) {
						$order = wc_get_order( $unpaid_order );
						if ( apply_filters( 'woocommerce_cancel_unpaid_order', 'checkout' === get_post_meta( $unpaid_order, '_created_via', true ), $order ) ) {
							if($result_resp=='SUCCESS'){
								$order->update_status( 'completed', __( 'Unpaid order completed - time limit reached.', 'woocommerce' ) );
							}
						}
				}
			}
		}
	}
	
  // Build the administration fields for this specific Gateway
    public function init_form_fields() {
       $this->form_fields = array(
                'enabled' => array(
                    'title'         => __('Enable/Disable', 'wc_gateway_atom'),
                    'type'             => 'checkbox',
                    'label'         => __('Enable Atom Paynetz Module.', 'wc_gateway_atom'),
                    'default'         => 'no',
                    'description'     => 'Show in the Payment List as a payment option'
                ),
                  'title' => array(
                    'title'         => __('Title:', 'wc_gateway_atom'),
                    'type'            => 'text',
                    'default'         => __('Atom Gateway Payments', 'wc_gateway_atom'),
                    'description'     => __('This controls the title which the user sees during checkout.', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
                'description' => array(
                    'title'         => __('Description:', 'wc_gateway_atom'),
                    'type'             => 'textarea',
                    'default'         => __("Pay securely by Credit or Debit Card or Internet Banking through Atom Technologies Secure Servers."),
                    'description'     => __('This controls the description which the user sees during checkout.', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
                'atom_domain' => array(
                    'title'         => __('Atom Domain', 'wc_gateway_atom'),
                    'type'             => 'text',
                    'description'     => __('Will be provided by Atom Paynetz Team after production movement', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
                'login_id' => array(
                    'title'         => __('Login Id', 'wc_gateway_atom'),
                    'type'             => 'text',
                    'description'     => __('As provided by Atom Paynetz Team', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
                'password' => array(
                    'title'         => __('Password', 'wc_gateway_atom'),
                    'type'             => 'password',
                    'description'     => __('As provided by Atom Paynetz Team', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
                'atom_prod_id'     => array(
                    'title'         => __('Product ID', 'wc_gateway_atom'),
                    'type'             => 'text',
                    'description'     =>  __('Will be provided by Atom Paynetz Team after production movement', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
				'atom_port'     => array(
                    'title'         => __('Port Number', 'wc_gateway_atom'),
                    'type'             => 'text',
                    'description'     =>  __('80 for Test Server & 443 for Production Server', 'wc_gateway_atom'),
                    'desc_tip'         => true
                ),
            );
	 }
    function check_atom_response(){
		global $woocommerce;
		 global $wpdb, $woocommerce;
		if(isset($_REQUEST['f_code'])){
			$order = new WC_Order($_REQUEST['mer_txn']);
			
			$VERIFIED		=	$_REQUEST['f_code'];
			if($VERIFIED == 'Ok'){
				$VERIFIED = 'complete';
			}else{
				$VERIFIED = 'pending';
			}
			
			$bank_name		=	$_REQUEST['bank_name'];
			$bank_txn		=	$_REQUEST['bank_txn'];
			$discriminator	=	$_REQUEST['discriminator'];
			
			if($_REQUEST['f_code']=='Ok'){
				$order->update_status('completed');
				$this -> msg['message'] = "Thank you for shopping with us. Your account has been charged <b>Rs".$_REQUEST['amt']."</b> and your transaction is successful. Bank Transaction ID is  : <b>".$_REQUEST['bank_txn']."</b>.";
                $this->msg['class'] = 'woocommerce-message';	
            } else {
				$order->update_status('failed');
                $this->msg['class'] = 'woocommerce-error';
				$this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
            }
			add_action('the_content', array(&$this, 'showMessage'));			 
        }
    }
	
	function showMessage($content){
       return '<div class="box '.$this->msg['class'].'">'.$this->msg['message'].'</div>'.$content;
    }
	
	// Submit payment and handle response
    public function process_payment( $order_id ) {
		global $woocommerce;
		global $current_user;
		//get user details   
		$current_user	= wp_get_current_user();
            
		$user_email     = $current_user->user_email;
		$first_name     = $current_user->shipping_first_name;
		$last_name      = $current_user->shipping_last_name;
		$phone_number   = $current_user->billing_phone;
		$country       	= $current_user->shipping_country;
		$state       	= $current_user->shipping_state;
		$city       	= $current_user->shipping_city;
		$postcode       = $current_user->shipping_postcode;
		$address_1      = $current_user->shipping_address_1;
		$address_2      = $current_user->shipping_address_2;
		$udf1 			= $first_name." ".$last_name;
		$udf2			= $user_email;
		$udf3			= $phone_number;
		$udf4			= $country." ".$state." ".shipping_city." ".$address_1." ".$address_2." ".$postcode;

		if($user_email == ''){
			$user_email 	= $_POST['billing_email'];
			$first_name 	= $_POST['billing_first_name'];
			$last_name  	= $_POST['billing_last_name'];
			$phone_number 	= $_POST['billing_phone'];
			$country       	= $_POST['billing_country'];
			$state       	= $_POST['billing_state'];
			$city       	= $_POST['billing_city'];
			$postcode       = $_POST['billing_postcode'];
			$address_1      = $_POST['billing_address_1'];
			$address_2      = $_POST['billing_address_2'];
			$udf1 		= $first_name." ".$last_name;
			$udf2		= $user_email;
			$udf3		= $phone_number;
			$udf4		= $country." ".$state." ".shipping_city." ".$address_1." ".$address_2." ".$postcode;
		}

	$order 			= new WC_Order( $order_id );
	$atom_login_id 	= $this->login_id;
        $atom_password 	= $this->password;
        $atom_prod_id 	= $this->atom_product_id;
        $amount 		= $order->get_total();
        $currency 		= "INR";
        $custacc 		= "1234567890";
        $txnid 			= $order_id;    
        $clientcode 	= urlencode(base64_encode(007));
        $datenow 		= date("d/m/Y h:m:s");
        $encodedDate 	= str_replace(" ", "%20", $datenow);
        $ru 			= $this->notify_url;
		
       $param = "&login=".$atom_login_id."&pass=".$atom_password."&ttype=NBFundTransfer"."&prodid=".$atom_prod_id."&amt=".$amount."&txncurr=".$currency."&txnscamt=0"."&clientcode=".$clientcode."&txnid=".$txnid."&date=".$encodedDate ."&custacc=".$custacc."&udf1=".$udf1."&udf2=".$udf2."&udf3=".$udf3."&udf4=".$udf4."&ru=".$ru;
	   global $wpdb, $woocommerce;
	   
	
        $ch = curl_init();
        $useragent = 'woo-commerce plugin';
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_PORT , $this->atom_port);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //information received from gateway is stored in $response.
        $response = curl_exec($ch);
		
		if(curl_errno($ch))
        {	
            echo '<div class="woocommerce-error">Curl error: "'. curl_error($ch).". Error in gateway credentials.</div>";
			die;
        }
        curl_close($ch);
        $parser = xml_parser_create('');
        xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, trim($response), $xml_values);
        xml_parser_free($parser);

        $returnArray = array();
        $returnArray['url'] = $xml_values[3]['value'];
        $returnArray['tempTxnId'] = $xml_values[5]['value'];
        $returnArray['token'] = $xml_values[6]['value'];    
		
		//code to generate form action
        $xmlObjArray = $returnArray;
		$url = $xmlObjArray['url'];
		
		$postFields  = "";
        $postFields .= "&ttype=NBfundTransfer";
        $postFields .= "&tempTxnId=".$xmlObjArray['tempTxnId'];
        $postFields .= "&token=".$xmlObjArray['token'];
        $postFields .= "&txnStage=1";
        $q = $url."?".$postFields;
		
		return array('result' => 'success', 'redirect' => $q);
		exit;
    }
}
	
    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'add_atom_gateway' );
    function add_atom_gateway( $methods ) {
        $methods[] = 'WC_Gateway_Atom';
		return $methods;
	}
}