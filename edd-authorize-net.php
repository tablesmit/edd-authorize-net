<?php
/*
Plugin Name: Easy Digital Downloads - Authorize.net Gateway
Plugin URL: http://easydigitaldownloads.com/extension/authorize-net
Description: Adds a payment gateway for Authorize.net
Version: 1.0.6
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/


if ( ! defined('EDDA_PLUGIN_DIR')) {
	define('EDDA_PLUGIN_DIR', dirname(__FILE__));
}

if( class_exists( 'EDD_License' ) && is_admin() ) {
	$license = new EDD_License( __FILE__, 'Authorize.net Payment Gateway', '1.0.6', 'Pippin Williamson' );
}

// registers the gateway
function edda_register_authorize_gateway($gateways) {
	// Format: ID => Name
	$gateways['authorize'] = array('admin_label' => __( 'Authorize.net', 'edda'), 'checkout_label' => __( 'Credit Card', 'edda'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'edda_register_authorize_gateway');

function edda_process_payment($purchase_data) {
	global $edd_options;

	if ( ! isset( $_POST['card_number'] ) || $_POST['card_number'] == '' ) {
		edd_set_error( 'empty_card', __( 'You must enter a card number', 'edd'));
	}
	if ( ! isset( $_POST['card_name'] ) || $_POST['card_name'] == '' ) {
		edd_set_error( 'empty_card_name', __( 'You must enter the name on your card', 'edd'));
	}
	if ( ! isset( $_POST['card_exp_month'] ) || $_POST['card_exp_month'] == '' ) {
		edd_set_error( 'empty_month', __( 'You must enter an expiration month', 'edd'));
	}
	if ( ! isset( $_POST['card_exp_year'] ) || $_POST['card_exp_year'] == '' ) {
		edd_set_error( 'empty_year', __( 'You must enter an expiration year', 'edd'));
	}
	if ( ! isset( $_POST['card_cvc'] ) || $_POST['card_cvc'] == '' || strlen( $_POST['card_cvc'] ) < 3 ) {
		edd_set_error( 'empty_cvc', __( 'You must enter a valid CVC', 'edd' ) );
	}

	$errors = edd_get_errors();
	if ( ! $errors ) {

		require_once( dirname( __FILE__ ) . '/includes/anet_php_sdk/AuthorizeNet.php' );

		$transaction = new AuthorizeNetAIM( edd_get_option( 'edda_api_login' ), edd_get_option( 'edd_transaction_key' ) );
		if(edd_is_test_mode()) {
			$transaction->setSandbox(true);
		} else {
			$transaction->setSandbox(false);
		}

		$card_info = $purchase_data['card_info'];

		$transaction->amount 		= $purchase_data['price'];
		$transaction->card_num 		= strip_tags( trim( $card_info['card_number'] ) );
		$transaction->card_code  	= strip_tags( trim( $card_info['card_cvc'] ) );
		$transaction->exp_date 		= strip_tags( trim( $card_info['card_exp_month'] ) ) . '/' . strip_tags( trim( $card_info['card_exp_year'] ) );

		$transaction->description 	= edd_get_purchase_summary( $purchase_data );
        $transaction->first_name 	= $purchase_data['user_info']['first_name'];
        $transaction->last_name 	= $purchase_data['user_info']['last_name'];

        $transaction->address 		= $card_info['card_address'] . ' ' . $card_info['card_address_2'];
        $transaction->city 			= $card_info['card_city'];
        $transaction->country 		= $card_info['card_country'];
        $transaction->state 		= $card_info['card_state'];
        $transaction->zip 			= $card_info['card_zip'];

        $transaction->customer_ip 	= edd_get_ip();
        $transaction->email 		= $purchase_data['user_email'];
        $transaction->invoice_num 	= $purchase_data['purchase_key'];

        try {

			$response = $transaction->authorizeAndCapture();

			if ( $response->approved ) {

				$payment_data = array(
					'price' 		=> $purchase_data['price'],
					'date' 			=> $purchase_data['date'],
					'user_email' 	=> $purchase_data['user_email'],
					'purchase_key' 	=> $purchase_data['purchase_key'],
					'currency' 		=> edd_get_currency(),
					'downloads' 	=> $purchase_data['downloads'],
					'cart_details' 	=> $purchase_data['cart_details'],
					'user_info' 	=> $purchase_data['user_info'],
					'status' 		=> 'pending'
				);

				$payment = edd_insert_payment($payment_data);
				if( $payment ) {
					edd_update_payment_status( $payment, 'publish' );
					edd_send_to_success_page();
				} else {
					edd_set_error( 'authorize_error', __( 'Error: your payment could not be recorded. Please try again', 'edda' ) );
					edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
				}
			} else {

				//echo '<pre>'; print_r( $response ); echo '</pre>'; exit;

				if( isset( $response->response_reason_text ) ) {
					$error = $response->response_reason_text;
				} elseif( isset( $response->error_message ) ) {
					$error = $response->error_message;
				} else {
					$error = '';
				}

				if( strpos( strtolower( $error ), 'the credit card number is invalid' ) !== false ) {
					edd_set_error( 'invalid_card', __( 'Your card number is invalid', 'edda' ) );
				} elseif( strpos( strtolower( $error ), 'this transaction has been declined' ) !== false ) {
					edd_set_error( 'invalid_card', __( 'Your card has been declined', 'edda' ) );
				} elseif( isset( $response->response_reason_text ) ) {
					edd_set_error( 'api_error', $response->response_reason_text );
				} elseif( isset( $response->error_message ) ) {
					edd_set_error( 'api_error', $response->error_message );
				} else {
					edd_set_error( 'api_error', sprintf( __( 'An error occurred. Error data: %s', 'edda' ), print_r( $response, true ) ) );
				}

				edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
			}
		} catch ( AuthorizeNetException $e ) {
			edd_set_error( 'request_error', $e->getMessage() );
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
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
			'name' => '<strong>' . __( 'Authorize.net Gateway Settings', 'edda') . '</strong>',
			'desc' => __( 'Configure your authorize.net Gateway Settings', 'edda'),
			'type' => 'header'
		),
		array(
			'id' => 'edda_api_login',
			'name' => __( 'API Login ID', 'edda'),
			'desc' => __( 'Enter your authorize.net API login ID', 'edda'),
			'type' => 'text'
		),
		array(
			'id' => 'edd_transaction_key',
			'name' => __( 'Transaction Key', 'edda'),
			'desc' => __( 'Enter your authorize.net transaction key', 'edda'),
			'type' => 'text'
		)
	);

	return array_merge($settings, $edda_settings);
}
add_filter('edd_settings_gateways', 'edda_add_settings');