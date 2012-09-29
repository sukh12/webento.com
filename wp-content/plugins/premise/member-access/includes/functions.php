<?php
/**
 * AccessPress Functions for use throughout the plugin
 *
 * @package AccessPress
 */

/**
 * Retrieve and return an option from the database.
 *
 * @since 0.1.0
 */
function accesspress_get_option( $key, $setting = null ) {

	/**
	 * Get setting. The default is set here, once, so it doesn't have to be
	 * repeated in the function arguments for accesspress_option() too.
	 */
	$setting = $setting ? $setting : MEMBER_ACCESS_SETTINGS_FIELD;

	/** setup caches */
	static $settings_cache = array();
	static $options_cache = array();

	/** Short circuit */
	$pre = apply_filters( 'accesspress_pre_get_option_'.$key, false, $setting );
	if ( false !== $pre )
		return $pre;

	/** Check options cache */
	if ( isset( $options_cache[$setting][$key] ) ) {

		// option has been cached
		return $options_cache[$setting][$key];

	}

	/** check settings cache */
	if ( isset( $settings_cache[$setting] ) ) {

		// setting has been cached
		$options = apply_filters( 'accesspress_options', $settings_cache[$setting], $setting );

	} else {

		// set value and cache setting
		$options = $settings_cache[$setting] = apply_filters( 'accesspress_options', get_option( $setting ), $setting );

	}

	// check for non-existent option
	if ( ! is_array( $options ) || ! array_key_exists( $key, (array) $options ) ) {

		// cache non-existent option
		$options_cache[$setting][$key] = '';

		return '';
	}

	// option has been cached, cache option
	$options_cache[$setting][$key] = is_array( $options[$key] ) ? stripslashes_deep( $options[$key] ) : stripslashes( wp_kses_decode_entities( $options[$key] ) );

	return $options_cache[$setting][$key];

}

/**
 * Retrieve and echo an option from the database.
 *
 * @since 0.1.0
 */
function accesspress_option( $key, $setting = null ) {
	echo accesspress_get_option( $key, $setting );
}

/**
 * Returns custom field post meta data.
 *
 * Return only the first value of custom field.
 * Returns false if field is blank or not set.
 *
 * @since 0.1.0
 *
 * @global integer $id Post ID.
 * @global stdClass $post Post object.
 * @param string $field Custom field key.
 * @return string|boolean Return value or false on failure.
 */
function accesspress_get_custom_field( $field, $default = '' ) {

	global $id, $post;

	if ( null === $id && null === $post )
		return $default;

	$post_id = null === $id ? $post->ID : $id;

	$custom_field = get_post_meta( $post_id, $field, true );

	if ( $custom_field )
		/** Sanitize and return the value of the custom field */
		return stripslashes( wp_kses_decode_entities( $custom_field ) );

	/** Return $default if custom field is empty */
	return $default;

}

/**
 * Echo data from a custom field.
 *
 * Echo only the first value of custom field.
 *
 * @since 0.1.0
 *
 * @uses accesspress_get_custom_field()
 *
 * @param string $field Custom field key.
 */
function accesspress_custom_field( $field, $default = '' ) {

	echo accesspress_get_custom_field( $field );

}

/**
 * This function redirects the user to an admin page, and adds query args
 * to the URL string for alerts, etc.
 *
 * @since 0.1.0
 */
function accesspress_admin_redirect( $page = '', $query_args = array() ) {

	if ( ! $page )
		return;

	$url = html_entity_decode( menu_page_url( $page, 0 ) );

	foreach ( (array) $query_args as $key => $value ) {
		if ( empty( $key ) && empty( $value ) ) {
			unset( $query_args[$key] );
		}
	}
	
	$url = add_query_arg( $query_args, $url ); 

	wp_redirect( esc_url_raw( $url ) );

}

/**
 * Helper function used to check that we're targeting a specific AccessPress admin page.
 *
 * @since 0.1.0
 *
 * @global string $page_hook Page hook for current page
 * @param string $pagehook Page hook string to check
 * @return boolean Returns true if the global $page_hook matches given $pagehook. False otherwise
 */
function accesspress_is_menu_page( $pagehook = '' ) {

	global $page_hook;

	if ( isset( $page_hook ) && $page_hook == $pagehook )
		return true;

	/* May be too early for $page_hook */
	if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] == $pagehook )
		return true;

	return false;

}

/**
 * Determines if a user has a certain active access level.
 *
 * Checks the user meta to see if the user has a particular access
 * level, and if it is still active.
 *
 * @since 0.1.0
 *
 * @param string $access_level.
 * @param string $user_id, optional.
 * @return boolean
 */
function member_has_access_level( $access_level = '', $user_id = '', $delay = 0 ) {

	/** Check to see if $user_id is provided. If not, assume current logged in user */
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	/** If user is not an AccessPress member, return false */
	if ( ! user_can( $user_id, 'access_membership' ) )
		return false;

	/** Pull all the orders the member has ever made */
	$orders = (array) get_user_option( 'acp_orders', $user_id );

	/** Initialize $active_subscriptions array */
	$active_subscriptions = array();

	/** Initial time calculations */
	$now = time();
	$delay = (int) $delay ? ( $delay * 86400 ) : 0;

	/** Cycle through $orders looking for active (non-expired) subscriptions */
	foreach ( $orders as $order ) {

		$product = (int) get_post_meta( $order, '_acp_order_product_id', true );

		$expiration = memberaccess_get_order_expiry( $order, $product, $delay );

		/** If subscription can expire and has expired, skip this iteration */
		if ( $expiration < 0 || ( $expiration > 0 && $now > $expiration ) )
			continue;
		
		/** If active, save the product ID */
		$active_subscriptions[] = $product;
		
	}

	/** If no active subscriptions, return false */
	if ( ! $active_subscriptions )
		return false;

	/** Cycle through active subscriptions, look for one that has the proper access level, return true as soon as we find one. */
	foreach ( (array) $access_level as $level ) {

		$level = sanitize_title_with_dashes( $level );
		foreach ( $active_subscriptions as $product ) {

			if ( has_term( $level, 'acp-access-level', $product ) )
				return true;

		}
	}

	/** Else, return false */
	return false;

}

/**
 * Retrieve the order expiry timestamp
 *
 * It retrieves the product ordered & calculated the expiry of the using product duration, delay & order time
 *
 * @since 0.1.0
 *
 * @param int $order_id.
 * @param optional int $delay.
 * @param optional bool $future.
 * @return int time
 */
function memberaccess_get_order_expiry( $order_id, $product_id, $delay = 0, $future = false ) {

	$order_timestamp = $delay + (int) get_post_meta( $order_id, '_acp_order_time', true );

	/** If there is a delay, not looking for future access & the member has not reached it, return 0 time */
	if ( $delay && ! $future && time() < $order_timestamp )
		return -1;

	$product_duration = (int) get_post_meta( $product_id, '_acp_product_duration', true );
	if ( ! $product_duration )
		return 0;

	/** offset the product order time when a delay is requested */
	$renew_timestamp = (int) get_post_meta( $order_id, '_acp_order_renewal_time', true );
	if ( $renew_timestamp )
		return $renew_timestamp + $delay;

	return strtotime( sprintf( '+ %d days', $product_duration ), $order_timestamp );

}

/**
 * Check to see if a particular product requires a payment.
 *
 * It first validates that there is an active payment method available.
 * Then, it checks the product to see if the user chose to require payment for that product.
 *
 * @since 0.1.0 
 *
 * @param string $product_id.
 * @return boolean
 */
function accesspress_product_requires_payment( $product_id = '' ) {

	if ( ! $product_id )
		return false;
	
	/** If no active payment methods, we can't require payment */
	if ( ! is_active_payment_method( 'paypal' ) && ! is_active_payment_method( 'authorize.net' ) )
		return false;

	/** If this is a 'paid' product, payment is required */
	if ( 'paid' == get_post_meta( $product_id, '_acp_access_method', true ) )
		return true;

	return false;

}

function is_valid_product_payment_method( $method = '', $product_id = '' ) {
	
	if ( ! $method || ! $product_id )
		return false;

	/** If product doesn't require payment, return false */
	if ( ! accesspress_product_requires_payment( $product_id ) )
		return false;

	/** If checking for Authorize.net */
	if ( 'authorize.net' == $method ) {
		return get_post_meta( $product_id, '_acp_payment_authorize_net', true );
	}
	/** If checking for PayPal */
	if ( 'paypal' == $method ) {
		return get_post_meta( $product_id, '_acp_payment_paypal', true );
	}
	/** If checking for Dummy Credit Card */
	if ( 'dummycc' == $method ) {
		return get_post_meta( $product_id, '_acp_payment_dummycc', true );
	}

	return false;
	
}

/**
 * Check to see if a particular payment method is active.
 *
 * Checks the options associated with a particular payment method to see if the user
 * has filled out the necessary fields. If yes, return true. If no, return false.
 *
 * @since 0.1.0 
 *
 * @param string $method.
 * @return boolean
 */
function is_active_payment_method( $method = '' ) {
	
	/** If PayPal */
	if ( 'paypal' == $method ) {

		if ( accesspress_get_option( 'paypal_express_username' ) && accesspress_get_option( 'paypal_express_password' ) && accesspress_get_option( 'paypal_express_signature' ) )
			return true;

	}
	
	/** If Authorize.net */
	elseif ( 'authorize.net' == $method ) {
		
		if ( accesspress_get_option( 'authorize_net_id' ) && accesspress_get_option( 'authorize_net_key' ) )
			return true;
		
	}
	
	return false;
	
}

/**
 * Returns a checklist of Access Levels for use in a form.
 *
 * @since 0.1.0
 *
 * @param array $args
 * @return string
 */
function accesspress_get_access_level_checklist( $args = array() ) {

	$args = wp_parse_args( $args, array(
		'name' => '',
		'selected' => array(),
		'style' => ''
	) );

	$output = '';

	$terms = get_terms( 'acp-access-level', array( 'hide_empty' => false ) );

	if ( ! $terms )
		return;

	foreach ( (array) $terms as $term ) {

		$selected = in_array( $term->term_id, (array) $args['selected'] ) ? 'checked="checked"' : '';

		$output .= sprintf( '<label><input type="checkbox" name="%s" value="%s" %s %s /> %s</label><br />', esc_html( $args['name'] ), esc_attr( $term->term_id ), $args['style'], $selected, esc_html( $term->name ) );

	}
	
	return $output;
	
}

/**
 * Returns an array of countries, by two letter country code, in an associative array.
 *
 * Pass this function a two-letter country code, and it will return that country at the
 * beginning of the array. Useful if you tend to sell many products in a particular country.
 *
 * @since 0.1.0
 *
 * @param string $top optional.
 * @return array
 */
function accesspress_get_countries( $top = 'US' ) {
	
	$countries = array(
		"US" => "United States",
		"AF" => "Afghanistan",
		"AL" => "Albania",
		"DZ" => "Algeria",
		"AS" => "American Samoa",
		"AD" => "Andorra",
		"AO" => "Angola",
		"AI" => "Anguilla",
		"AQ" => "Antarctica",
		"AG" => "Antigua And Barbuda",
		"AR" => "Argentina",
		"AM" => "Armenia",
		"AW" => "Aruba",
		"AU" => "Australia",
		"AT" => "Austria",
		"AZ" => "Azerbaijan",
		"BS" => "Bahamas",
		"BH" => "Bahrain",
		"BD" => "Bangladesh",
		"BB" => "Barbados",
		"BY" => "Belarus",
		"BE" => "Belgium",
		"BZ" => "Belize",
		"BJ" => "Benin",
		"BM" => "Bermuda",
		"BT" => "Bhutan",
		"BO" => "Bolivia",
		"BA" => "Bosnia And Herzegowina",
		"BW" => "Botswana",
		"BV" => "Bouvet Island",
		"BR" => "Brazil",
		"IO" => "British Indian Ocean Territory",
		"BN" => "Brunei Darussalam",
		"BG" => "Bulgaria",
		"BF" => "Burkina Faso",
		"BI" => "Burundi",
		"KH" => "Cambodia",
		"CM" => "Cameroon",
		"CA" => "Canada",
		"CV" => "Cape Verde",
		"KY" => "Cayman Islands",
		"CF" => "Central African Republic",
		"TD" => "Chad",
		"CL" => "Chile",
		"CN" => "China",
		"CX" => "Christmas Island",
		"CC" => "Cocos (Keeling) Islands",
		"CO" => "Colombia",
		"KM" => "Comoros",
		"CG" => "Congo",
		"CD" => "Congo, The Democratic Republic Of The",
		"CK" => "Cook Islands",
		"CR" => "Costa Rica",
		"CI" => "Cote D'Ivoire",
		"HR" => "Croatia (Local Name: Hrvatska)",
		"CU" => "Cuba",
		"CY" => "Cyprus",
		"CZ" => "Czech Republic",
		"DK" => "Denmark",
		"DJ" => "Djibouti",
		"DM" => "Dominica",
		"DO" => "Dominican Republic",
		"TP" => "East Timor",
		"EC" => "Ecuador",
		"EG" => "Egypt",
		"SV" => "El Salvador",
		"GQ" => "Equatorial Guinea",
		"ER" => "Eritrea",
		"EE" => "Estonia",
		"ET" => "Ethiopia",
		"FK" => "Falkland Islands (Malvinas)",
		"FO" => "Faroe Islands",
		"FJ" => "Fiji",
		"FI" => "Finland",
		"FR" => "France",
		"FX" => "France, Metropolitan",
		"GF" => "French Guiana",
		"PF" => "French Polynesia",
		"TF" => "French Southern Territories",
		"GA" => "Gabon",
		"GM" => "Gambia",
		"GE" => "Georgia",
		"DE" => "Germany",
		"GH" => "Ghana",
		"GI" => "Gibraltar",
		"GR" => "Greece",
		"GL" => "Greenland",
		"GD" => "Grenada",
		"GP" => "Guadeloupe",
		"GU" => "Guam",
		"GT" => "Guatemala",
		"GN" => "Guinea",
		"GW" => "Guinea-Bissau",
		"GY" => "Guyana",
		"HT" => "Haiti",
		"HM" => "Heard And Mc Donald Islands",
		"VA" => "Holy See (Vatican City State)",
		"HN" => "Honduras",
		"HK" => "Hong Kong",
		"HU" => "Hungary",
		"IS" => "Iceland",
		"IN" => "India",
		"ID" => "Indonesia",
		"IR" => "Iran (Islamic Republic Of)",
		"IQ" => "Iraq",
		"IE" => "Ireland",
		"IL" => "Israel",
		"IT" => "Italy",
		"JM" => "Jamaica",
		"JP" => "Japan",
		"JO" => "Jordan",
		"KZ" => "Kazakhstan",
		"KE" => "Kenya",
		"KI" => "Kiribati",
		"KP" => "Korea, Democratic People's Republic Of",
		"KR" => "Korea, Republic Of",
		"KW" => "Kuwait",
		"KG" => "Kyrgyzstan",
		"LA" => "Lao People's Democratic Republic",
		"LV" => "Latvia",
		"LB" => "Lebanon",
		"LS" => "Lesotho",
		"LR" => "Liberia",
		"LY" => "Libyan Arab Jamahiriya",
		"LI" => "Liechtenstein",
		"LT" => "Lithuania",
		"LU" => "Luxembourg",
		"MO" => "Macau",
		"MK" => "Macedonia, Former Yugoslav Republic Of",
		"MG" => "Madagascar",
		"MW" => "Malawi",
		"MY" => "Malaysia",
		"MV" => "Maldives",
		"ML" => "Mali",
		"MT" => "Malta",
		"MH" => "Marshall Islands",
		"MQ" => "Martinique",
		"MR" => "Mauritania",
		"MU" => "Mauritius",
		"YT" => "Mayotte",
		"MX" => "Mexico",
		"FM" => "Micronesia, Federated States Of",
		"MD" => "Moldova, Republic Of",
		"MC" => "Monaco",
		"MN" => "Mongolia",
		"MS" => "Montserrat",
		"MA" => "Morocco",
		"MZ" => "Mozambique",
		"MM" => "Myanmar",
		"NA" => "Namibia",
		"NR" => "Nauru",
		"NP" => "Nepal",
		"NL" => "Netherlands",
		"AN" => "Netherlands Antilles",
		"NC" => "New Caledonia",
		"NZ" => "New Zealand",
		"NI" => "Nicaragua",
		"NE" => "Niger",
		"NG" => "Nigeria",
		"NU" => "Niue",
		"NF" => "Norfolk Island",
		"MP" => "Northern Mariana Islands",
		"NO" => "Norway",
		"OM" => "Oman",
		"PK" => "Pakistan",
		"PW" => "Palau",
		"PA" => "Panama",
		"PG" => "Papua New Guinea",
		"PY" => "Paraguay",
		"PE" => "Peru",
		"PH" => "Philippines",
		"PN" => "Pitcairn",
		"PL" => "Poland",
		"PT" => "Portugal",
		"PR" => "Puerto Rico",
		"QA" => "Qatar",
		"RE" => "Reunion",
		"RO" => "Romania",
		"RU" => "Russian Federation",
		"RW" => "Rwanda",
		"KN" => "Saint Kitts And Nevis",
		"LC" => "Saint Lucia",
		"VC" => "Saint Vincent And The Grenadines",
		"WS" => "Samoa",
		"SM" => "San Marino",
		"ST" => "Sao Tome And Principe",
		"SA" => "Saudi Arabia",
		"SN" => "Senegal",
		"SC" => "Seychelles",
		"SL" => "Sierra Leone",
		"SG" => "Singapore",
		"SK" => "Slovakia (Slovak Republic)",
		"SI" => "Slovenia",
		"SB" => "Solomon Islands",
		"SO" => "Somalia",
		"ZA" => "South Africa",
		"GS" => "South Georgia, South Sandwich Islands",
		"ES" => "Spain",
		"LK" => "Sri Lanka",
		"SH" => "St. Helena",
		"PM" => "St. Pierre And Miquelon",
		"SD" => "Sudan",
		"SR" => "Suriname",
		"SJ" => "Svalbard And Jan Mayen Islands",
		"SZ" => "Swaziland",
		"SE" => "Sweden",
		"CH" => "Switzerland",
		"SY" => "Syrian Arab Republic",
		"TW" => "Taiwan",
		"TJ" => "Tajikistan",
		"TZ" => "Tanzania, United Republic Of",
		"TH" => "Thailand",
		"TG" => "Togo",
		"TK" => "Tokelau",
		"TO" => "Tonga",
		"TT" => "Trinidad And Tobago",
		"TN" => "Tunisia",
		"TR" => "Turkey",
		"TM" => "Turkmenistan",
		"TC" => "Turks And Caicos Islands",
		"TV" => "Tuvalu",
		"UG" => "Uganda",
		"UA" => "Ukraine",
		"AE" => "United Arab Emirates",
		"GB" => "United Kingdom",
		"UM" => "United States Minor Outlying Islands",
		"UY" => "Uruguay",
		"UZ" => "Uzbekistan",
		"VU" => "Vanuatu",
		"VE" => "Venezuela",
		"VN" => "Viet Nam",
		"VG" => "Virgin Islands (British)",
		"VI" => "Virgin Islands (U.S.)",
		"WF" => "Wallis And Futuna Islands",
		"EH" => "Western Sahara",
		"YE" => "Yemen",
		"YU" => "Yugoslavia",
		"ZM" => "Zambia",
		"ZW" => "Zimbabwe"
	);
	
	/** Default */
	if ( 'US' == $top || ! array_key_exists( $top, $countries ) )
		return $countries;
		
	/** Define our new top element */
	$new_top = array( $top => $countries[$top] );
	
	/** Remove the $top element from the haystack */
	unset( $countries[$top] );
	
	/** Add it back, at the top */
	$countries = array_merge( $new_top, $countries );
	
	return $countries;
	
}

/**
 * 
 */
function accesspress_checkout( $args = array() ) {

	global $accesspress_checkout_member, $wpdb;

	$args = wp_parse_args( $args, array(
		'product_id' => '',
		'renew' => '',
		
		'member' => 0,
		'member-key' => '',
		'first-name' => '',
		'last-name' => '',
		'email' => '',
		'username' => '',
		'password' => '',
		'password-repeat' => '',
		
		'payment-method' => '',
		
		'card-name' => '',
		'card-number' => '',
		'card-month' => '',
		'card-year' => '',
		'card-security' => '',
		'card-country' => '',
		'card-postal' => '',
	) );
	
	/** Trim space from values */
	$args = array_map( 'trim', $args );

	// instantiate gateway
	if ( 'cc' == $args['payment-method'] )
		$gateway = new AccessPress_AuthorizeNet_Gateway();
	else
		$gateway = new AccessPress_Paypal_Gateway();

	// check for a completed transaction first
	$completed_transaction = $gateway->complete_sale( $args );
	if ( is_wp_error( $completed_transaction ) )
		return $completed_transaction;

	if ( $completed_transaction ) {
		
		$report_back = false;
		extract( $completed_transaction );

	} else {

		// handle report back
		$report_back = $gateway->validate_reportback();
		if ( is_wp_error( $report_back ) )
			return $report_back;

		if ( $report_back ) {

			// show confirmation form
			if ( method_exists( $gateway, 'confirmation_form' ) )
				return $gateway->confirmation_form( $report_back );

			extract( $report_back );

		}
	}

	// populate $args from posted form
	if ( ! $report_back && ! $completed_transaction ) {

		/** If order ID not set */
		if ( ! $args['product_id'] )
			return new WP_Error( 'product_id_not_set', 'The product ID was not set.' );

		/** check for resubmit where member was created */
		if ( $args['member'] && $args['member-key'] && wp_verify_nonce( $args['member-key'], 'checkout-member-' . $args['member'] ) )
			$member = $args['member'];

		/** If account info not filled out */
		elseif ( ! $args['first-name'] || ! $args['last-name']  || ! $args['email'] || ! $args['username'] || ! $args['password'] || ! $args['password-repeat']  )
			return new WP_Error( 'account_info_not_filled_out', 'The account information was not filled out.' );

		/** If passwords do not match */
		elseif ( $args['password'] !== $args['password-repeat'] )
			return new WP_Error( 'account_passwords_do_not_match', 'The passwords do not match.' );

		/** If no payment method selected */
		if ( ! $args['payment-method'] && accesspress_product_requires_payment( $args['product_id'] ) )
			return new WP_Error( 'payment_method_not_chosen', 'No payment method was chosen.' );

	}

	/** The order array, to be stored as an Order (CPT) */
	if ( ! isset( $order_details ) ) {

		$duration = get_post_meta( $args['product_id'], '_acp_product_duration', true );
		if ( $duration && 'true' == $args['renew'] ) {

			$member_orders = get_user_option( 'acp_orders', (int) $member );
			if ( ! empty( $member_orders ) ) {

				$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_acp_order_product_id' AND meta_value = %s AND post_id IN (" . implode( ',', $member_orders ) .')', $args['product_id'] ) );
				$order_time = get_post_meta( $order_id, '_acp_order_time', true );
				$order_renewal_time = get_post_meta( $order_id, '_acp_order_renewal_time', true );

				if ( $order_time ) {

					$order_details = array(
						'_acp_order_renewal_time'       => ( $order_renewal_time ? $order_renewal_time : $order_time ) + ( $duration * 86400 ),
						'_acp_order_price'		=> get_post_meta( $args['product_id'], '_acp_product_price', true ),
						'_acp_order_id'			=> $order_id,
					);

				}
			}
		}

		if ( empty( $order_details ) ) {

			$order_details = array(
				'_acp_order_time'       => time(),
				'_acp_order_status'     => 'complete',
				'_acp_order_product_id' => $args['product_id'],
				'_acp_order_price'      => get_post_meta( $args['product_id'], '_acp_product_price', true ),
			);

		}
	}

	/** If CC payment method selected, but information not filled out */
	if ( 'cc' == $args['payment-method'] ) {
		
		if ( ! $args['card-name'] || ! $args['card-number'] || ! $args['card-month'] || ! $args['card-year'] || ! $args['card-security'] || ! $args['card-country'] || ! $args['card-postal'] )
			return new WP_Error( 'credit_card_not_filled_out', 'The credit card info was not completed.' );

	}

	/** Create member before sending to gateway so we have a unique ID */
	if ( ! isset( $member ) ) {

		$member = accesspress_create_member( array(
			'first_name' => $args['first-name'],
			'last_name'  => $args['last-name'],
			'user_email' => $args['email'],
			'user_login' => $args['username'],
			'user_pass'  => $args['password'],
		) );

	}

	/** Bail, if there's a problem */
	if ( is_wp_error( $member ) )
		return $member;

	/** Add member ID to order details */
	$order_details['_acp_order_member_id'] = $accesspress_checkout_member = $member;

	/** now to the gateway */
	if ( ! $report_back && ! $completed_transaction ) {

		$args['order_details'] = $order_details;
		$order_details = $gateway->process_order( $args );

	}

	/** Bail, if the order is incomplete or there's an error on the gateway */
	if ( empty( $order_details ) || is_wp_error( $order_details ) )
		return $order_details;

	return accesspress_create_order( $member, $order_details );

}
function accesspress_create_order( $member, $order_details ) {

	$renewal = ! empty( $order_details['_acp_order_id'] );
	if ( $renewal ) {

		$order = $order_details['_acp_order_id'];
		unset( $order_details['_acp_order_id'] );

	} else {

		/** Create Order */
		$order = wp_insert_post( array(
			'post_title'  => $order_details['_acp_order_time'],
			'post_status' => 'publish',
			'post_type'   => 'acp-orders',
			'ping_status' => 0,
			'post_parent' => 0,
			'menu_order'  => 0,
		) );

		/** Bail, if there's a problem */
		if ( is_wp_error( $order ) )
			return $order;

	}

	/** Complete order information from $order_details array */
	foreach ( (array) $order_details as $key => $value )
		update_post_meta( (int) $order, $key, $value );


	/** Add Order ID to user meta */
	memberaccess_add_order_to_member( $member, $order );

	do_action( 'premise_membership_create_order', $member, $order_details, $renewal );

	/** Return Order ID and Member ID on success */
	return array(
		'order_id'  => $order,
		'member_id' => $member
	);

}

function accesspress_get_checkout_link( $product_id = 0 ) {

	$checkout_page = accesspress_get_option( 'checkout_page' );
	if ( $checkout_page )
		return esc_url( add_query_arg( array( 'product_id'=> $product_id ), get_permalink( $checkout_page ) ) );

	return '';

}

function memberaccess_is_vbulletin_enabled() {

	global $vbulletin;

	return ( accesspress_get_option( 'vbulletin_bridge' ) && defined( 'VBULLETIN_PATH' ) && is_object( $vbulletin ) );

}

function memberaccess_get_email_receipt_address() {

	$email_from = accesspress_get_option( 'email_receipt_address' );
	if ( ! empty( $email_from ) )
		return $email_from;

	$domain = str_replace( array( 'http://', 'https://' ), '', home_url() );

	if ( ( $index = strpos( $domain, ':' ) ) !== false )
		$domain = substr( $domain, 0, $index );

	if ( ( $index = strpos( $domain, '/' ) ) !== false )
		$domain = substr( $domain, 0, $index );

	return 'wordpress@' . $domain;
	
}

function memberaccess_login_redirect( $redirect_to = '' ) {

	$login_page = accesspress_get_option( 'login_page' );
	if ( $login_page )
		$login_url = get_permalink( $login_page );
	else
		$login_url = home_url( 'wp-login.php' );

	return add_query_arg( array( 'redirect_to' => $redirect_to ), $login_url );

}

function memberaccess_is_valid_product( $product_id ) {

	if ( ! (int)$product_id )
		return false;

	$product = get_post( $product_id );
	if ( ! $product || 'acp-products' != $product->post_type || 'publish' != $product->post_status )
		return false;

	return true;

}

/** Add Order to user meta */
function memberaccess_add_order_to_member( $member_id, $order_id ) {

	$member_orders = get_user_option( 'acp_orders', (int) $member_id );
	if ( ! is_array( $member_orders ) )
		$member_orders = array();

	$member_orders[] = (int) $order_id;
	update_user_option( (int) $member_id, 'acp_orders', array_unique( $member_orders ) );

}

function memberaccess_get_cron_key() {

	$salt = is_multisite() ? network_home_url() : home_url();
	$salt .= ABSPATH . wp_salt( 'auth' );

	return sha1( $salt );

}

function memberaccess_cancel_subscription( $order_id ) {

	$cancel_status = __( 'cancel', 'premise' );
	$status = get_post_meta( $order_id, '_acp_order_status', true );
	if ( $status && $cancel_status != $status )
		update_post_meta( $order_id, '_acp_order_status', $cancel_status );

}