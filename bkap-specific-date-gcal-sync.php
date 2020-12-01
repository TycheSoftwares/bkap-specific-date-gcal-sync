<?php 
/**
 * Plugin Name: Export Specific Dates to Google Calendar
 * Description: Exporting the specific dates of the product to Google Calendar
 * Version: 1.0
 * Author: Tyche Softwares
 * Author URI: http://www.tychesoftwares.com/
 * Requires PHP: 5.6
 * WC requires at least: 3.0.0
 * WC tested up to: 3.3.4
 * Text Domain: bkap-specific-date-gcal-sync
 */

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BKAP_Specific_Date_Gcal_Sync' ) ) :


include_once( 'bkap-sg-class.php' );
/**
 * Booking & Appointment Plugin Specific Dates Google Calendar Class
 * 
 * @class BKAP_Specific_Date_Gcal_Sync
 */
class BKAP_Specific_Date_Gcal_Sync {

	/**
	 * Default constructor
	 *
	 * @since 1.0
	 */
	public function __construct() {

		if ( ! defined( 'BKAPSG_PLUGIN_PATH' ) ) {
			define( 'BKAPSG_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
		}

			add_action( 'admin_init', array( &$this, 'bkapsg_include_files' ) );
			add_action( 'bkap_after_import_events_settings', array( $this, 'bkap_after_import_events_settings_callback' ) );

			//add_filter( 'admin_init',                           array( $this, 'bkap_additional_data_after_timeslots_calculator_callback' ), 10, 3 );
			add_action( 'bkap_delete_timeslot', 				array( $this, 'bkap_delete_timeslot_callback' ), 10, 4 );
			//add_action( 'admin_init',                           array( $this, 'bkapsg_admin_init' ) );
			add_action( 'woocommerce_bkap_import_events', array( $this, 'bkapsg_import_events_cron' ) );

			add_action( 'woocommerce_bkap_import_events'/* 'admin_init' */, array( $this, 'bkap_admin_insert_event_callback' ) );
	}

	public static function bkap_admin_insert_event_callback( $product_id = 0 ) {
		$product_id = 0;
		if ( $product_id == 0 ) {

			$all_products = bkap_common::get_woocommerce_product_list( false );

			$product_list = array();

			if ( isset( $all_products ) && count( $all_products ) > 0 ) {
				foreach ( $all_products as $a_key => $a_value ) {
					$pro_id     = $a_value[ 1 ];
					$bookable   = bkap_common::bkap_get_bookable_status( $a_value[ 1 ] );
					// if the product is bookable
					if ( $bookable ) {

						

						$sgcal_info         = get_post_meta( $pro_id, '_bkap_date_gcal_ids', array( '123', '456' ) );
						$bkap_time_settings = get_post_meta( $pro_id, '_bkap_time_settings', array() );

						if ( $sgcal_info === '' ) {
							$sgcal_info = array( '123' => '456' );
						}

						if ( count( $bkap_time_settings ) > 0 ) {

							foreach ( $bkap_time_settings as $key => $value ) {
								if ( ! empty( $value ) ) {

									foreach( $value as $k => $v ) {

										if ( strpos( $k, '-' ) !== false ) {

											if ( ! empty( $v ) ) {
												foreach ( $v as $timeslot_k => $timeslot_v ) { // aLL TIMESLOTS FOR PERTICULAR DATE
													$from 			= $timeslot_v[ 'from_slot_hrs' ] . $timeslot_v[ 'from_slot_min' ];
													$to 			= $timeslot_v[ 'to_slot_hrs' ] . $timeslot_v[ 'to_slot_min' ];  
													$from_time      = $timeslot_v[ 'from_slot_hrs' ] . ":" . $timeslot_v[ 'from_slot_min' ];
													$to_time 		= $timeslot_v[ 'to_slot_hrs' ] . ":" .$timeslot_v[ 'to_slot_min' ];  
													$specific_key 	= $k . ':' . $from . '-' . $to;
													$event_details  = array();

													if ( ! isset( $sgcal_info[ $specific_key ] ) ) {

														$uid = '';
														$event_details[ 'hidden_booking_date' ] = $k;
														$event_details[ 'product_id' ]          = $pro_id;
														$event_details[ 'time_slot' ]           = $from_time . " - ". $to_time;

														$product_post  = get_post( $pro_id );
														$recent_author = get_user_by( 'ID', $product_post->post_author );
														$event_details[ 'author_name' ] = $recent_author->display_name;
														
														$uid = self::bkapsg_insert_event_to_gcal( $event_details );

														if ( $uid  ) {
															$sgcal_info[ $specific_key ] = $uid;
														}
													}
												}
											}
										}
									}

									update_post_meta( $pro_id, '_bkap_date_gcal_ids', $sgcal_info );
								}
							}
						}
					}
				}
			}   
		}
	}

	public static function bkapsg_import_events_cron() {

		$bkapsg_delete_events_uids = get_option( 'bkapsg_delete_events_uids', array() );

		if ( count( $bkapsg_delete_events_uids ) > 0 ) {
			$key        = get_option( 'bkap_specific_date_key_file' );
			$service    = get_option( 'bkap_specific_date_service_account' );
			$calendar   = get_option( 'bkap_specific_date_calendar_used' );
			$gcal       = new BKAP_Gcal( $key, $service, $calendar );
			// $gcal->bkapsg_init();

			$pro_id     = 0;
			$user_id    = get_current_user_id(); // user ID

			foreach( $bkapsg_delete_events_uids as $key => $event_id ) {
				unset( $bkapsg_delete_events_uids[$key] );
				$gcal->delete_event( 0, $user_id, $pro_id, $event_id );
				update_option( 'bkapsg_delete_events_uids', $bkapsg_delete_events_uids );
			}
		}
	}

	public static function bkapsg_admin_init() {


		$bkapsg_delete_events_uids = get_option( 'bkapsg_delete_events_uids', array() );

		if ( count( $bkapsg_delete_events_uids ) > 0 ) {
			$gcal               = new BKAP_Gcal( );
			// $gcal->bkapsg_init();

			$pro_id             = 0;
			$user_id            = get_current_user_id(); // user ID

			foreach( $bkapsg_delete_events_uids as $key => $event_id ) {
				unset( $bkapsg_delete_events_uids[$key] );
				$gcal->delete_event( 0, $user_id, $pro_id, $event_id );
				//update_option( 'bkapsg_delete_events_uids', $bkapsg_delete_events_uids );
			}
		}
	}

	public function bkapsg_include_files() {

		global $wpdb;

		$sgbkap_data = get_option( 'sgbkap_data_deleted', 'no' );

		if ( $sgbkap_data == 'no'  ) {

			$select_ids = "SELECT * FROM `" . $wpdb->prefix . "postmeta` WHERE meta_key LIKE '_bkap_date_gcal_ids';";    
			$check_weekday = $wpdb->get_results( $select_ids );

			$delete_ids = "DELETE FROM `" . $wpdb->prefix . "postmeta` WHERE meta_key LIKE '_bkap_date_gcal_ids';";
			$wpdb->query( $delete_ids );
			delete_option( 'bkapsg_delete_events_uids' );
			update_option( 'sgbkap_data_deleted', 'yes' );
		}
		include_once( /* BKAPSG_PLUGIN_PATH . '/ */'bkap-sg-functions.php' );
		include_once( /* BKAPSG_PLUGIN_PATH . '/ */'bkap-sg-class.php' );

	}

	public static function bkap_additional_data_after_timeslots_calculator_callback( /* $settings_data, $product_id, $clean_settings_data */ ) {

		$product_ids = bkap_common::get_woocommerce_product_list( false, 'on', 'yes' );

		foreach ( $product_ids as $pkey => $pvalue ) {

			$sgcal_info = get_post_meta( $product_id, '_bkap_date_gcal_ids', array() );

			if ( $sgcal_info == "" ) {
				$sgcal_info = array();
			}

			$bkap_time_settings = $settings_data[ '_bkap_time_settings' ];

			foreach ( $bkap_time_settings as $key => $value ) {

				foreach ( $value as $k => $v ) {

					$from 			= $v[ 'from_slot_hrs' ] . $v[ 'from_slot_min' ];
					$to 			= $v[ 'to_slot_hrs' ] . $v[ 'to_slot_min' ];  
					$from_time      = $v[ 'from_slot_hrs' ] . ":" . $v[ 'from_slot_min' ];
					$to_time 		= $v[ 'to_slot_hrs' ] . ":" .$v[ 'to_slot_min' ];  
					$specific_key 	= $key . ':' . $from . '-' . $to;
					$event_details  = array();

					if ( ! isset( $sgcal_info[ $specific_key ] ) ) {

						$uid = '';
						$event_details[ 'hidden_booking_date' ] = $key;
						$event_details[ 'product_id' ] = $product_id;
						$event_details[ 'time_slot' ] = $from_time . " - ". $to_time;

						$uid = self::bkapsg_insert_event_to_gcal( $event_details );

						if ( $uid ) {
							$sgcal_info[ $specific_key ] = $uid;
						}
					}
				}
			}

			update_post_meta( $product_id, '_bkap_date_gcal_ids', $sgcal_info );
		}

		//return $settings_data;
	}

	public static function bkapsg_insert_event_to_gcal( $event_details, $product_id = 0 ) {

		$uid                = '';
		$user_id            = get_current_user_id();
		$gcal               = new BKAP_Specific_Gcal( "extra" );
		$gcal->bkapsg_init();

		if ( $gcal->get_api_mode( $user_id, $product_id ) == "directly" ) {
			$uid = $gcal->insert_event( $event_details, 0, $user_id, $product_id, false );                        
		}

		return $uid;
	}

	public static function bkap_delete_timeslot_callback( $product_id, $day_value, $from_time, $to_time ) {
		$pro_id             = $product_id;
		$user_id            = get_current_user_id(); // user ID
		//$gcal               = new BKAP_Gcal( "extra" );
		//$gcal->bkapsg_init();
		include_once( BKAPSG_PLUGIN_PATH . '/bkap-sg-class.php' );
		$bkap_date_gcal_ids   = get_post_meta( $product_id, '_bkap_date_gcal_ids', true );
		//delete_option( 'bkapsg_delete_events_uids' );
		if ( '' !== $bkap_date_gcal_ids && count( $bkap_date_gcal_ids ) ){
			//if ( $gcal->get_api_mode( $user_id, $product_id ) == "directly" ) {

				$from_time 	= str_replace( ":", "", $from_time );
				$to_time 	= str_replace( ":", "", $to_time );

				$specific_key = $day_value . ':' . $from_time . '-' . $to_time;

				if ( isset( $bkap_date_gcal_ids[ $specific_key ] ) ) {

					$bkapsg_delete_events_uids = get_option( 'bkapsg_delete_events_uids', array() );
					$event_id = $bkap_date_gcal_ids[ $specific_key ];
					if ( in_array( $event_id, $bkapsg_delete_events_uids ) ) {
					} else {
						$bkapsg_delete_events_uids[] = $event_id;
					}
					update_option( 'bkapsg_delete_events_uids', $bkapsg_delete_events_uids );
					//$gcal->delete_event( 0, $user_id, $pro_id, $event_id );	
				}
			//}	
		}
	}

	public static function bkap_after_import_events_settings_callback(){

		add_settings_section(
		   'bkap_google_calendar_sync_section',
		   __( 'Specific Dates and Google Calendar Sync', 'woocommerce-booking' ),
		   array( 'BKAP_Specific_Date_Gcal_Sync', 'bkap_google_calendar_sync_section_callback' ),
		   'bkap_gcal_sync_settings_page'
		);
   
		add_settings_field(
		   'bkap_specific_date_key_file',
		   __( 'Key File:', 'woocommerce-booking'  ),
		   array( 'BKAP_Specific_Date_Gcal_Sync', 'bkap_specific_date_key_file_callback' ),
		   'bkap_gcal_sync_settings_page',
		   'bkap_google_calendar_sync_section',
		   array( '<br>Enter key file name here without extention, e.g. ab12345678901234567890-privatekey.', 'woocommerce-booking' )
		);

		add_settings_field(
		   'bkap_specific_date_service_account',
		   __( 'Service account email address:', 'woocommerce-booking'  ),
		   array( 'BKAP_Specific_Date_Gcal_Sync', 'bkap_specific_date_service_account_callback' ),
		   'bkap_gcal_sync_settings_page',
		   'bkap_google_calendar_sync_section',
		   array( '<br>Enter Service account email address here, e.g. 1234567890@developer.gserviceaccount.com.', 'woocommerce-booking' )
		);

		add_settings_field(
		   'bkap_specific_date_calendar_used',
		   __( 'Calendar to be used	:', 'woocommerce-booking'  ),
		   array( 'BKAP_Specific_Date_Gcal_Sync', 'bkap_specific_date_calendar_used_callback' ),
		   'bkap_gcal_sync_settings_page',
		   'bkap_google_calendar_sync_section',
		   array( '<br>Enter the ID of the calendar in which your bookings will be saved, e.g. abcdefg1234567890@group.calendar.google.com.', 'woocommerce-booking' )
		);

		register_setting(
		   'bkap_gcal_sync_settings',
		   'bkap_specific_date_key_file'
		);

		register_setting(
		   'bkap_gcal_sync_settings',
		   'bkap_specific_date_service_account'
		);

		register_setting(
		   'bkap_gcal_sync_settings',
		   'bkap_specific_date_calendar_used'
		);
	}

	public static function bkap_google_calendar_sync_section_callback(){}

	public static function bkap_specific_date_key_file_callback( $args ){

		$bkap_specific_date_key_file = '';
		if( get_option( 'bkap_specific_date_key_file' ) == "" ) {
			$bkap_specific_date_key_file = "";
		} else {
			$bkap_specific_date_key_file = get_option( 'bkap_specific_date_key_file' );
		}
		echo '<input type="text" size="50" name="bkap_specific_date_key_file" id="bkap_specific_date_key_file" value="' . esc_attr( $bkap_specific_date_key_file ) . '" />';
		$html = '<label for="bkap_specific_date_key_file"> ' . $args[0] . '</label>';
		echo $html;
	}

	public static function bkap_specific_date_service_account_callback( $args ){

		$bkap_specific_date_service_account = '';
		if( get_option( 'bkap_specific_date_service_account' ) == "" ) {
			$bkap_specific_date_service_account = "";
		} else {
			$bkap_specific_date_service_account = get_option( 'bkap_specific_date_service_account' );
		}
		echo '<input type="text" size="50" name="bkap_specific_date_service_account" id="bkap_specific_date_service_account" value="' . esc_attr( $bkap_specific_date_service_account ) . '" />';
		$html = '<label for="bkap_specific_date_service_account"> ' . $args[0] . '</label>';
		echo $html;
	}

	public static function bkap_specific_date_calendar_used_callback( $args ){

		$bkap_specific_date_calendar_used = '';
		if( get_option( 'bkap_specific_date_calendar_used' ) == "" ) {
			$bkap_specific_date_calendar_used = "";
		} else {
			$bkap_specific_date_calendar_used = get_option( 'bkap_specific_date_calendar_used' );
		}
		echo '<input type="text" size="50" name="bkap_specific_date_calendar_used" id="bkap_specific_date_calendar_used" value="' . esc_attr( $bkap_specific_date_calendar_used ) . '" />';
		$html = '<label for="bkap_specific_date_calendar_used"> ' . $args[0] . '</label>';
		echo $html;
	}
}
$bkap_specific_date_gcal_sync = new BKAP_Specific_Date_Gcal_Sync();

endif;