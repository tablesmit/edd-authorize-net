<?php
/*
Plugin Name: Easy Digital Downloads - Authorize.net Gateway
Plugin URL: http://easydigitaldownloads.com/extension/authorize-net
Description: Adds a payment gateway for Authorize.net
Version: 1.0
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/


if(!defined('EDDA_PLUGIN_DIR')) {
	define('EDDA_PLUGIN_DIR', dirname(__FILE__));
}

// registers the gateway
function edda_register_authorize_gateway($gateways) {
	// Format: ID => Name
	$gateways['authorize'] = array('admin_label' => __('Authorize.net', 'edda'), 'checkout_label' => __('Credit Card', 'edda'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'edda_register_authorize_gateway');

function edda_authorize_remove_cc_form() {
	// we only register the action so that the default CC form is not shown
}
add_action('edd_authorize_cc_form', 'edda_authorize_remove_cc_form');

function edda_process_payment($purchase_data) {
	global $edd_options;

	if(!isset($_POST['card_number']) || $_POST['card_number'] == '') {
		edd_set_error('empty_card', __('You must enter a card number', 'edd'));
	}
	if(!isset($_POST['card_name']) || $_POST['card_name'] == '') {
		edd_set_error('empty_card_name', __('You must enter the name on your card', 'edd'));
	}
	if(!isset($_POST['card_exp_month']) || $_POST['card_exp_month'] == '') {
		edd_set_error('empty_month', __('You must enter an expiration month', 'edd'));
	}
	if(!isset($_POST['card_exp_year']) || $_POST['card_exp_year'] == '') {
		edd_set_error('empty_year', __('You must enter an expiration year', 'edd'));
	}
	if(!isset($_POST['card_cvc']) || $_POST['card_cvc'] == '' || strlen($_POST['card_cvc']) < 3) {
		edd_set_error('empty_cvc', __('You must enter a valid CVC', 'edd'));
	}
	
	$errors = edd_get_errors();
	if(!$errors) {
	
		require_once(dirname(__FILE__) . '/includes/anet_php_sdk/AuthorizeNet.php');
		
		$transaction = new AuthorizeNetAIM($edd_options['edda_api_login'], $edd_options['edd_transaction_key']);
		if(edd_is_test_mode()) {
			$transaction->setSandbox(true);
		} else {
			$transaction->setSandbox(false);
		}
		$transaction->amount = $purchase_data['price'];
		$transaction->card_num = strip_tags(trim($_POST['card_number']));
		$transaction->exp_date = strip_tags(trim($_POST['card_exp_month'])) . '/' . strip_tags(trim($_POST['card_exp_year']));

		$response = $transaction->authorizeAndCapture();

		if ($response->approved) {
		
			$payment_data = array( 
				'price' => $purchase_data['price'], 
				'date' => $purchase_data['date'], 
				'user_email' => $purchase_data['post_data']['edd-email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'user_info' => $purchase_data['user_info'],
				'status' => 'pending'
			);
		
			$payment = edd_insert_payment($payment_data);
			if($payment) {
				edd_update_payment_status($payment, 'publish');
				edd_send_to_success_page();
			} else {
				edd_set_error('authorize_error', __('Error: your payment could not be recorded. Please try again', 'edda'));
				edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
			}
		} else {
			if(strpos($response->error_message, 'The credit card number is invalid') !== false) {
				edd_set_error('invalid_card', __('Your card number is invalid', 'edd'));
			}
			
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
	} else {
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}
}
add_action('edd_gateway_authorize', 'edda_process_payment');

// adds the settings to the Payment Gateways section
function edda_add_settings($settings) {
  
  $edda_settings = array(
		array(
			'id' => 'edda_settings',
			'name' => '<strong>' . __('Authorize.net Gateway Settings', 'edda') . '</strong>',
			'desc' => __('Configure your authorize.net Gateway Settings', 'edda'),
			'type' => 'header'
		),
		array(
			'id' => 'edda_api_login',
			'name' => __('API Login ID', 'edda'),
			'desc' => __('Enter your authorize.net API login ID', 'edda'),
			'type' => 'text'
		),
		array(
			'id' => 'edd_transaction_key',
			'name' => __('Transaction Key', 'edda'),
			'desc' => __('Enter your authorize.net transaction key', 'edda'),
			'type' => 'text'
		)
	);
	
	return array_merge($settings, $edda_settings);
}
add_filter('edd_settings_gateways', 'edda_add_settings');

// setup a custom CC form for Stripe
function edda_authorize_cc_form() {
	ob_start(); ?>
	<?php do_action('edd_before_authorize_cc_fields'); ?>
	<fieldset>
		<legend><?php _e('Credit Card Info', 'edd'); ?></legend>
		<p>
			<input type="text" autocomplete="off" name="card_name" class="card-name edd-input required" />
			<label class="edd-label"><?php _e('Name on the Card', 'edd'); ?></label>
		</p>
		<p>
			<input type="text" autocomplete="off" name="card_number" class="card-number edd-input required" />
			<label class="edd-label"><?php _e('Card Number', 'edd'); ?></label>
		</p>
		<p>
			<input type="text" size="4" autocomplete="off" name="card_cvc" class="card-cvc edd-input required" />
			<label class="edd-label"><?php _e('CVC', 'edd'); ?></label>
		</p>
		<?php do_action('edd_before_authorize_cc_expiration'); ?>
		<p class="card-expiration">
			<input type="text" size="2" name="card_exp_month" class="card-expiry-month edd-input required"/>
			<span class="exp-divider"> / </span>
			<input type="text" size="2" name="card_exp_year" class="card-expiry-year edd-input required"/>
			<label class="edd-label"><?php _e('Expiration (MM/YY)', 'edd'); ?></label>
		</p>
	</fieldset>
	<?php do_action('edd_after_authorize_cc_fields'); ?>
	<?php
	echo ob_get_clean();
}
add_action('edd_authorize_cc_form', 'edda_authorize_cc_form');