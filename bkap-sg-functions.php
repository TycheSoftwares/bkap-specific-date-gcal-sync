<?php

/**
 * Insert event to Google Calendar for the given order item id 
 *
 * @param object $order_obj Order Object
 * @param int $product_id Product ID
 * @param int $item_id Order Item ID
 * @since 4.8.0
 */

/*function bkapsg_insert_event_to_gcal( $product_id = 0 ) {

    $user_id            = get_current_user_id();
    $gcal               = new BKAP_Specific_Gcal();
    
    $uid                = '';

    if ( $gcal->get_api_mode( $user_id, $product_id ) == "directly" ) {
        $event_details  = array();
        $uid = $gcal->insert_event( $event_details, 0, $user_id, $product_id, true );                        
    }

    return $uid;
}*/


function bkapsg_get_timezone_string(){
	$current_offset     = get_option( 'gmt_offset' );
	$tzstring           = get_option( 'timezone_string' );
	$check_zone_info    = true;

	// Remove old Etc mappings. Fallback to gmt_offset.
	if ( false !== strpos( $tzstring, 'Etc/GMT' ) ) {
		$tzstring = '';
	}

	if ( empty( $tzstring ) ) { // Create a UTC+- zone if no timezone string exists
		$check_zone_info = false;
		if ( 0 == $current_offset ) {
			$tzstring = 'UTC+0';
		} elseif ( $current_offset < 0 ) {
			$tzstring = 'UTC' . $current_offset;
		} else {
			$tzstring = 'UTC+' . $current_offset;
		}
    }
    
    return $tzstring;
}

function sgbkap_get_date_as_per_timezone( $date_string, $timezone_string = '' ) {
	
	/* $timezone   = new DateTimeZone( $timezone_string );
	$date       = new DateTime( $date_string );
	//$date->setTimezone( $timezone );
	//var_dump($july1->format("r") );

	//var_dump( $july1 );

	$date_timestamp = $date->getTimestamp();
	//var_dump($date_timestamp);
	$dateformat 	= $date->format( 'Y-m-d\TH:i:s\Z' ); */

	// https://stackoverflow.com/questions/32139407/php-convert-local-time-to-utc/32139499
	$tz_from 	= $timezone_string;
	$tz_to 		= 'UTC';
	$format 	= 'Y-m-d\TH:i:s\Z';

	$dt = new DateTime( $date_string, new DateTimeZone( $tz_from ) );
	$dt->setTimeZone( new DateTimeZone( $tz_to ) );
	$dateformat = $dt->format( $format );

	return $dateformat;
}



function bkapsg_testing_timesone(){

	$current_offset     = get_option( 'gmt_offset' );
	$tzstring           = get_option( 'timezone_string' );
	$check_zone_info    = true;

	// Remove old Etc mappings. Fallback to gmt_offset.
	if ( false !== strpos( $tzstring, 'Etc/GMT' ) ) {
		$tzstring = '';
	}

	if ( empty( $tzstring ) ) { // Create a UTC+- zone if no timezone string exists
		$check_zone_info = false;
		if ( 0 == $current_offset ) {
			$tzstring = 'UTC+0';
		} elseif ( $current_offset < 0 ) {
			$tzstring = 'UTC' . $current_offset;
		} else {
			$tzstring = 'UTC+' . $current_offset;
		}
	}
	
	if ( $check_zone_info && $tzstring ) :
		
		$now = new DateTime( '2020-03-30 15:00', new DateTimeZone( $tzstring ) );
		$dst = (bool) $now->format( 'I' ); // it is true

		$allowed_zones = timezone_identifiers_list(); // all timezones

		if ( in_array( $tzstring, $allowed_zones ) ) {
			$found                   = false;
			$date_time_zone_selected = new DateTimeZone( $tzstring );
			$tz_offset               = timezone_offset_get( $date_time_zone_selected, date_create() );
			$right_now               = time();
			foreach ( timezone_transitions_get( $date_time_zone_selected ) as $tr ) {
				
				if ( $tr['ts'] > $right_now ) {
					$found = true;
					break;
				}
			}

			if ( $found ) {
				echo ' ';
				$message = $tr['isdst'] ?
					/* translators: %s: Date and time. */
					__( 'Daylight saving time begins on: %s.' ) :
					/* translators: %s: Date and time. */
					__( 'Standard time begins on: %s.' );
				// Add the difference between the current offset and the new offset to ts to get the correct transition time from date_i18n().
				printf(
					$message,
					'<code>' . date_i18n(
						__( 'F j, Y' ) . ' ' . __( 'g:i a' ),
						$tr['ts'] + ( $tz_offset - $tr['offset'] )
					) . '</code>'
				);
			}
		}
		endif;
}
?>