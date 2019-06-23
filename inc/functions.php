<?php
/**
 * @package MPesa For WooCommerce
 * @subpackage Plugin Functions
 * @author Mauko Maunde < hi@mauko.co.ke >
 * @since 0.18.01
 */

//add_action('plugins_loaded', 'wc_mpesa_config', 15);
function wc_mpesa_config() 
{
	$c2b = get_option('woocommerce_mpesa_settings');
	Osen\Mpesa\C2B::set(
		array(
			'env' 			=> $c2b['env'],
			'appkey' 		=> $c2b['key'],
			'appsecret' 	=> $c2b['secret'],
			'headoffice' 	=> $c2b['headoffice'],
			'shortcode' 	=> $c2b['shortcode'],
			'type'	 		=> $c2b['idtype'],
			'passkey'	 	=> $c2b['passkey'],
			'validate' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/validate/action/0/base/c2b/',
			'confirm' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/confirm/action/0/base/c2b/',
			'reconcile' 	=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/reconcile/action/wc_mpesa_reconcile/base/c2b/',
			'timeout' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/timeout/action/wc_mpesa_timeout/base/c2b/'
		)
	);

	//b2c
	$b2c = get_option('b2c_wcmpesa_options');
	Osen\Mpesa\B2C::set(
		array(
			'env' 			=> $b2c['env'],
			'appkey' 		=> $b2c['appkey'],
			'appsecret' 	=> $b2c['appsecret'],
			'shortcode' 	=> $b2c['shortcode'],
			'type'	 		=> 4,
			'username'	 	=> $b2c['username'],
			'password'	 	=> $b2c['password'],
			'validate' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/validate/action/0/base/b2c/',
			'confirm' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/confirm/action/0/base/b2c/',
			'reconcile' 	=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/reconcile/action/wc_mpesa_reconcile/base/b2c/',
			'timeout' 		=> rtrim(home_url(), '/').':'.$_SERVER['SERVER_PORT'].'/wcmpesa/timeout/action/wc_mpesa_timeout/base/b2c/'
		)
	);
}

function get_post_id_by_meta_key_and_value($key, $value) {
    global $wpdb;
    $meta = $wpdb->get_results("SELECT * FROM `".$wpdb->postmeta."` WHERE meta_key='".$key."' AND meta_value='".$value."'");
    if (is_array($meta) && !empty($meta) && isset($meta[0])) {
        $meta = $meta[0];
    }

    if (is_object($meta)) {
        return $meta->post_id;
    } else {
        return false;
    }
}

function wc_mpesa_reconcile($response){
	if(empty($response)){
    	return array(
    		'errorCode' 	=> 1,
    		'errorMessage' 	=> 'Empty reconciliation response data' 
    	);
    }

    $resultCode 						= $response['stkCallback']['ResultCode'];
	$resultDesc 						= $response['stkCallback']['ResultDesc'];
	$merchantRequestID 					= $response['stkCallback']['MerchantRequestID'];
	$checkoutRequestID 					= $response['stkCallback']['CheckoutRequestID'];

	$post = get_post_id_by_meta_key_and_value('_request_id', $merchantRequestID);
	wp_update_post([ 'post_content' => file_get_contents('php://input'), 'ID' => $post ]);

    $order_id 							= get_post_meta($post, '_order_id', true);
	$amount_due 						=  get_post_meta($post, '_amount', true);
	$before_ipn_paid 					= get_post_meta($post, '_paid', true);

	if(wc_get_order($order_id)){
		$order 							= new WC_Order($order_id);
		$first_name 					= $order->get_billing_first_name();
		$last_name 						= $order->get_billing_last_name();
		$customer 						= $first_name." ".$last_name;
	} else {
		$customer 						= "MPesa Customer";
	}

	if(isset($response['stkCallback']['CallbackMetadata'])){
		$amount 						= $response['stkCallback']['CallbackMetadata']['Item'][0]['Value'];
		$mpesaReceiptNumber 			= $response['stkCallback']['CallbackMetadata']['Item'][1]['Value'];
		$balance 						= $response['stkCallback']['CallbackMetadata']['Item'][2]['Value'];
		$transactionDate 				= $response['stkCallback']['CallbackMetadata']['Item'][3]['Value'];
		$phone 							= $response['stkCallback']['CallbackMetadata']['Item'][4]['Value'];

		$after_ipn_paid 				= round($before_ipn_paid)+round($amount);
		$ipn_balance 					= $after_ipn_paid-$amount_due;

	    if(wc_get_order($order_id)){
	    	$order = new WC_Order($order_id);
	    	
	    	if ($ipn_balance == 0) {
				$mpesa = get_option('woocommerce_mpesa_settings');
				if ('processing' == $order->status){ $order->update_status('completed'); }
				$order->update_status('completed');
				$order->payment_complete();
	        	$order->add_order_note(__("Full MPesa Payment Received From {$phone} With Receipt Number {$mpesaReceiptNumber}"));
				update_post_meta($post, '_order_status', 'complete');
	        } elseif ($ipn_balance < 0) {
	        	$currency = get_woocommerce_currency();
	        	$order->payment_complete();
	            $order->add_order_note(__("{$phone} Has Overpayed By {$currency} {$balance}. Receipt Number {$mpesaReceiptNumber}"));
				update_post_meta($post, '_order_status', 'complete');
	        } else {
	            $order->update_status('on-hold');
	            $order->add_order_note(__("MPesa Payment from {$phone} Incomplete"));
				update_post_meta($post, '_order_status', 'on-hold');
	        }
	    }

		update_post_meta($post, '_paid', $after_ipn_paid);
		update_post_meta($post, '_amount', $amount_due);
		update_post_meta($post, '_balance', $balance);
		update_post_meta($post, '_phone', $phone);
		update_post_meta($post, '_customer', $customer);
		update_post_meta($post, '_order_id', $order_id);
		update_post_meta($post, '_receipt', $mpesaReceiptNumber);
	} else {
	    if(wc_get_order($order_id)){
	    	$order = new WC_Order($order_id);
	        $order->update_status('on-hold');
	        $order->add_order_note(__("MPesa Error {$resultCode}: {$resultDesc}"));
	    }
	}
}

function wc_mpesa_timeout($response)
{
	if(empty($response)){
    	return array(
    		'errorCode' 	=> 1,
    		'errorMessage' 	=> 'Empty timeout response data' 
    	);
    }
 	
 	$resultCode 					= $response['stkCallback']['ResultCode'];
	$resultDesc 					= $response['stkCallback']['ResultDesc'];
	$merchantRequestID 				= $response['stkCallback']['MerchantRequestID'];
	$checkoutRequestID 				= $response['stkCallback']['CheckoutRequestID'];

	$post = get_post_id_by_meta_key_and_value('_request_id', $merchantRequestID);
	wp_update_post([ 'post_content' => file_get_contents('php://input'), 'ID' => $post ]);
	update_post_meta($post, '_order_status', 'pending');

    $order_id = get_post_meta($post, '_order_id', true);
    if(wc_get_order($order_id)){
    	$order = new WC_Order($order_id);
    	
        $order->update_status('pending');
        $order->add_order_note(__("MPesa Payment Timed Out", 'woocommerce'));
    }
}

add_action( 'init', 'pmg_rewrite_add_rewrites' );
function pmg_rewrite_add_rewrites()
{
    add_rewrite_rule('wcmpesa/([^/]*)/?', 'index.php?wcmpesa=$matches[1]', 'top' );
    add_rewrite_rule('wcmpesa/([^/]*)/action/([^/]*)', 'index.php?wcmpesa=$matches[1]&action=$matches[2]', 'top' );
    add_rewrite_rule('wcmpesa/([^/]*)/action/([^/]*)/base/([^/]*)', 'index.php?wcmpesa=$matches[1]&action=$matches[2]&base=$matches[3]', 'top' );
}

add_filter('query_vars', function($query_vars) {
    $query_vars[] = 'wcmpesa';
    $query_vars[] = 'action';
    $query_vars[] = 'base';
    return $query_vars;
});

add_action('template_redirect', function() {
	header('Access-Control-Allow-Origin: *');
	
    $route 			= get_query_var('wcmpesa');
    $action 		= get_query_var('action');
    $api 			= get_query_var('base', 'stk');

    if (!empty($route)) {
		$response 	= json_decode(file_get_contents('php://input'), true);
		$data 		= isset($response['Body']) ? $response['Body'] : $response;
    	$action 	= ($action == '0') ? null : $action;

    	exit(
    		wp_send_json(
	    		call_user_func_array(
	      			array(
	      				'Osen\\Mpesa\\'.strtoupper($api),
	      				$route
	      			), 
	      			array(
	      				$action, 
	      				$data 
	      			) 
	      		)
	    	)
    	);
    }
});
