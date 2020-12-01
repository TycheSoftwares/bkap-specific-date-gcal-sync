<?php
/**
 * Bookings and Appointment Plugin for WooCommerce
 *
 * Class for Google Calendar API for WooCommerce Booking and Appointment Plugin
 *
 * @author   Tyche Softwares
 * @package  BKAP/Google-Calendar-Sync
 * @category Classes
 * @since 2.6
 */

include_once( /* BKAPSG_PLUGIN_PATH . '/ */'bkap-sg-functions.php' );
if ( !class_exists( 'BKAP_Specific_Gcal' ) ) {
    
    /**
     * Class for Google Calendar API for WooCommerce Booking and Appointment Plugin
     * @class BKAP_Gcal
     */
    class BKAP_Specific_Gcal {
        /**
         * Construct
         */
        function __construct( $extra = "" ) {
            global $wpdb;

            $this->extra      = $extra;
            $this->plugin_dir = BKAP_PLUGIN_PATH . '/includes/';
            $this->plugin_url = BKAP_PLUGIN_URL . '/includes';
            $this->local_time = current_time( 'timestamp' );
          
            $global_settings = json_decode( get_option( 'woocommerce_booking_global_settings' ) );
            $this->time_format = ( isset( $global_settings->booking_time_format ) ) ? $global_settings->booking_time_format : "H:i";
            
            $this->date_format = ( isset( $global_settings->booking_date_format ) ) ? $global_settings->booking_date_format : "Y-m-d";
            
            $this->datetime_format = $this->date_format . " " . $this->time_format;

            // Set log file location
            $uploads = wp_upload_dir();
            if ( isset( $uploads[ "basedir" ] ) ) {
                $this->uploads_dir  = $uploads[ "basedir" ] . "/";
            } else {
                $this->uploads_dir  = WP_CONTENT_DIR . "/uploads/";
            }
            
            $this->log_file = $this->uploads_dir . "bkap-log.txt";
            
    		require_once $this->plugin_dir . 'external/google/Client.php';
    		
    		//add_action( 'admin_init', array( $this, 'bkapsg_init' ), 12 );
    		
    		// Prevent exceptions to kill the page
    		if ( ( isset( $_POST[ 'gcal_api_test' ] ) && 1 == $_POST[ 'gcal_api_test' ] )
    			|| ( isset( $_POST['gcal_import_now'] ) && $_POST[ 'gcal_import_now' ] ) ) {
    			set_exception_handler( array( &$this, 'exception_error_handler' ) );
            }
    		
    		add_action( 'wp_ajax_display_nag', array( &$this, "display_nag" ) );
        }
	   
        /**
	    * Refresh the page with the exception as GET parameter, so that page is not killed
	    * 
	    * @param string $exception
	    * @since 2.6
	    */
        function exception_error_handler( $exception ) {
	       // If we don't remove these GETs there will be an infinite loop
	       if ( !headers_sent() ) {
	           wp_redirect( esc_url( add_query_arg( array( 'gcal_api_test_result' => urlencode( $exception ), 'gcal_import_now' => false, 'gcal_api_test' => false, 'gcal_api_pre_test' => false ) ) ) );
	       } else {
	           $this->log( $exception );
	       }
        }
	   
        /**
	    * Displays Messages
	    * @since 2.6
	    */
        function display_nag() {
	       $error = false;
	       $message = '';
	       
	       $user_id = $_POST[ 'user_id' ];

	       $product_id = 0;
	       $product_id = $_POST[ 'product_id' ];
	       
	       if ( isset( $_POST[ 'gcal_api_test' ] ) && 1 == $_POST[ 'gcal_api_test' ] ) {
   	           $result = $this->is_not_suitable( $user_id, $product_id );
	           if ( '' != $result ) {           
	               $message .= $result;
	           } else {
	               // Insert a test event
	               $result = $this->insert_event( array(), 0, $user_id, $product_id, true );
	               if ( $result ) {
	                   $message .= __( '<b>Test is successful</b>. Please REFRESH your Google Calendar and check that test appointment has been saved.', 'woocommerce-booking' );
	               } else {
	                   $log_path = $this->uploads_dir . "bkap-log.txt"; 
	                   $message .= __( "<b>Test failed</b>. Please inspect your log located at $log_path for more info.", 'woocommerce-booking' );
	               }
	           }
	       }
	        
	       if ( isset( $_POST[ 'gcal_api_test_result' ] ) && '' != $_POST[ 'gcal_api_test_result' ] ) {
	           $m = stripslashes( urldecode( $_POST[ 'gcal_api_test_result' ] ) );
	           // Get rid of unnecessary information
	           if ( strpos( $m, 'Stack trace' ) !== false ) {
	               $temp = explode( 'Stack trace', $m );
	               $m = $temp[0];
	           }
	           if ( strpos( $this->get_selected_calendar( $user_id, $product_id ), 'group.calendar.google.com' ) === false ) {
	               $add = '<br />'. __( 'Do NOT use your primary Google calendar, but create a new one.', 'woocommerce-booking' );
	           } else {
	               $add = '';
	           }
	           $message = __( 'The following error has been reported by Google Calendar API:<br />', 'woocommerce-booking' ) . $m . '<br />' .
	               __( '<b>Recommendation:</b> Please double check your settings.' . $add, 'woocommerce-booking' );
	       }
	       
	       echo $message;
	       die();
        }
        
        /**
         * Set some default settings related to GCal
         * 
         * @since 2.6
         */
        function bkapsg_init() {
        
            $product_id = 0;
            $user_id    = get_current_user_id();
            if ( 'disabled' != $this->get_api_mode( $user_id, $product_id ) && '' != $this->get_api_mode( $user_id, $product_id ) ) {
                // Try to create key file folder if it doesn't exist
                $this->create_key_file_folder( );
                $kff = $this->key_file_folder( );
                 
                // Copy index.php to this folder and to uploads folder
                if ( is_dir( $kff ) && !file_exists( $kff . 'index.php' ) ) {
                    echo "copying index file <br>";
                    @copy( $this->plugin_dir . 'gcal/key/index.php', $kff . 'index.php' );
                }
                if ( is_dir( $this->uploads_dir ) && !file_exists( $this->uploads_dir . 'index.php') ) {
                    @copy( $this->plugin_dir . 'gcal/key/index.php', $this->uploads_dir . 'index.php' );
                }
                 
                // Copy key file to uploads folder
                $kfn = $this->get_key_file( $user_id, $product_id ). '.p12';
                if ( $kfn && is_dir( $kff ) && !file_exists( $kff . $kfn ) && file_exists( $this->plugin_dir . 'gcal/key/' . $kfn ) ) {
                    @copy( $this->plugin_dir . 'gcal/key/' . $kfn, $kff . $kfn );
                }
            }
        }
        
        /**
         * Try to create an encrypted key file folder
         * @return string
         * @since 2.6
         */
        function create_key_file_folder( ) {
            if ( !is_dir( $this->uploads_dir . 'bkap_uploads/' ) ) {
                @mkdir( $this->uploads_dir . 'bkap_uploads/' );
            }
        }
	   
        /**
	    * Return GCal API mode (directly, manual, disabled )
	    * 
	    * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	* @param integer $product_id - Product ID. Greater than 0 for product level calendars.
	    * @return string
	    * 
	    * @since 2.6
	    */
        function get_api_mode( $user_id, $product_id = 0 ) {
            return "directly";
        }
	   
        /**
    	 * Return GCal service account
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return string
    	 * 
    	 * @since 2.6
    	 */
        function get_service_account( $user_id, $product_id ) {
            return get_option( 'bkap_specific_date_service_account' );
    	}
    	
    	/**
    	 * Return GCal key file name without the extension
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return string
    	 * 
    	 * @since 2.6
    	 */
    	function get_key_file( $user_id, $product_id ) {
    	    return get_option( 'bkap_specific_date_key_file' );
    	}
    	
    	/**
    	 * Return GCal selected calendar ID
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return string
    	 * 
    	 * @since 2.6
    	 */
    	function get_selected_calendar( $user_id, $product_id ) {
    	    return get_option( 'bkap_specific_date_calendar_used' );
    	}
    	
    	/**
    	 * Return GCal Summary (name of Event)
    	 * 
    	 * @return string
    	 * @since 2.6
    	 */
    	function get_summary() {
    	    return get_option( 'bkap_calendar_event_summary' );
    	}
    	
    	/**
    	 * Return GCal description
    	 * 
    	 * @return string
    	 * @since 2.6
    	 */
    	function get_description() {
    	    return get_option( 'bkap_calendar_event_description' );
    	}
    
    	/**
    	 * Checks if php version and extentions are correct
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return string (Empty string means suitable)
    	 * 
    	 * @since 2.6
    	 */
    	function is_not_suitable( $user_id, $product_id ) {
    	    	
    	    if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
    	        return __( 'Google PHP API Client <b>requires at least PHP 5.3</b>', 'woocommerce-booking' );
    	    }
    	
    	    // Disabled for now
    	    if ( false && memory_get_usage() < 31000000 ) {
    	        return sprintf( __( 'Google PHP API Client <b>requires at least 32 MByte Server RAM</b>. Please check this link how to increase it: %s','appointments'), '<a href="http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP" target="_blank">'.__( 'Increasing_memory_allocated_to_PHP', 'woocommerce-booking' ).'</a>' );
    	    }
    	
    	    if ( !function_exists( 'curl_init' ) ) {
    	        return __( 'Google PHP API Client <b>requires the CURL PHP extension</b>', 'woocommerce-booking' );
    	    }
    	
    	    if ( !function_exists( 'json_decode' ) ) {
    	        return __( 'Google PHP API Client <b>requires the JSON PHP extension</b>', 'woocommerce-booking' );
    	    }
    	
    	    if ( !function_exists( 'http_build_query' ) ) {
    	        return __( 'Google PHP API Client <b>requires http_build_query()</b>', 'woocommerce-booking' );
    	    }
    	
    	    // Dont continue further if this is pre check
    	    if ( isset( $_POST[ 'gcal_api_pre_test' ] ) && 1== $_POST[ 'gcal_api_pre_test' ] ) {
    	        return __( 'Your server installation meets requirements.', 'woocommerce-booking' );
    	    }
    	
    	    if ( !$this->_file_exists( $user_id, $product_id ) ) {
    	        return __( '<b>Key file does not exist</b>', 'woocommerce-booking' );
    	    }
    	
    	    return '';
    	}
    	
    	/**
    	 * Checks if key file exists
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return boolean
    	 * 
    	 * @since 2.6
    	 */
    	function _file_exists( $user_id, $product_id ) {
            if ( file_exists( $this->key_file_folder(). $this->get_key_file( $user_id, $product_id ) . '.p12' ) ) {
    	        return true;
    	    } else if ( file_exists( $this->plugin_dir . 'gcal/key/'. $this->get_key_file( $user_id, $product_id ) . '.p12' ) ) {
    	        return true;
    	    } else {
    	        return false;
    	    }
    	}
    	
    	/**
    	 * Get contents of the key file
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return string
    	 * 
    	 * @since 2.6
    	 */
    	function _file_get_contents( $user_id, $product_id ) {
    	    if ( file_exists( $this->key_file_folder( ). $this->get_key_file( $user_id, $product_id ) . '.p12' ) ) {
    	        return @file_get_contents( $this->key_file_folder(). $this->get_key_file( $user_id, $product_id ) . '.p12' );
    	    } else if ( file_exists( $this->plugin_dir . 'gcal/key/'. $this->get_key_file( $user_id, $product_id ) . '.p12' ) ) {
    	        return @file_get_contents( $this->plugin_dir . 'gcal/key/'. $this->get_key_file( $user_id, $product_id ) . '.p12' );
    	    } else {
    	        return '';
    	    }
    	}
    	
    	/**
    	 * Return key file folder name
    	 * 
    	 * @return string
    	 * @since 2.6
    	 */
    	function key_file_folder( ) {
    	    return $this->uploads_dir . 'bkap_uploads/';
    	}
    	
    	/**
    	 * Checks for settings and prerequisites
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return boolean
    	 * 
    	 * @since 2.6
    	 */
    	function is_active( $user_id,$product_id ) {
    	    // If integration is disabled, nothing to do
    	    if ( 'disabled' == $this->get_api_mode( $user_id, $product_id ) || '' == $this->get_api_mode( $user_id, $product_id ) || !$this->get_api_mode( $user_id, $product_id ) ) {
    	        return false;
    	    }

    	    if ( $this->is_not_suitable( $user_id, $product_id ) ) {
    	        return false;
    	    }
    	
    	    if ( $this->get_key_file( $user_id, $product_id ) &&  $this->get_service_account( $user_id, $product_id ) && $this->get_selected_calendar( $user_id, $product_id ) ) {
    	        return true;
    	    }
    	    // None of the other cases are allowed
    	    return false;
    	}
    	
    	/**
    	 * Connects to GCal API
    	 * 
    	 * @param integer $user_id - User ID. Greater than 0 for Tor Operator calendars
    	 * @param integer $product_id - Product ID. Greater than 0 for product level calendars.
    	 * @return boolean
    	 * 
    	 * @since 2.6
    	 */
    	function connect( $user_id, $product_id ) {
    	    // Disallow faultly plugins to ruin what we are trying to do here
    	    @ob_start();
    	
    	    if ( !$this->is_active( $user_id, $product_id ) ) {
    	        return false;
    	    }
    	    // Just in case
    	    require_once $this->plugin_dir . 'external/google/Client.php';
    	
    	    $config = new BKAP_Google_BKAPGoogleConfig( apply_filters( 'bkap-gcal-client_parameters', array(
//    	        'cache_class' => 'BKAP_Google_Cache_Null', // For an example
    	    )));
    	
    	    $this->client = new BKAP_Google_Client( $config );
    	    $this->client->setApplicationName( "WooCommerce Booking and Appointment" );;
    	    $key = $this->_file_get_contents( $user_id, $product_id );
    	    $this->client->setAssertionCredentials( new BKAP_Google_Auth_AssertionCredentials(
    	        $this->get_service_account( $user_id, $product_id ),
    	        array( 'https://www.googleapis.com/auth/calendar' ),
    	        $key)
    	    );
    	
    	    $this->service = new BKAP_Google_Service_Calendar( $this->client );
    	
    	    return true;
    	}
    	
    	/**
    	 * Creates a Google Event object and set its parameters
    	 * 
    	 * @param app: Booking object to be set as event
    	 * @since 2.6
    	 */
    	function set_event_parameters( $app ) {

    	    if ( get_option( 'bkap_calendar_event_location' ) != "" ) {
    	        $location = str_replace( array( 'ADDRESS', 'CITY' ), array( /*$app->client_address*/'Kartik Address', 'Kartik City'/*$app->client_city*/ ), get_option( 'bkap_calendar_event_location' ) );
    	    } else {
    	        $location = get_bloginfo( 'description' );
    	    }
    	
    	    $summary = str_replace( array( 'SITE_NAME',
                                            'CLIENT',
                                            'PRODUCT_NAME',
                                            'PRODUCT_WITH_QTY',
                                            'ORDER_DATE_TIME',
                                            'ORDER_DATE',
                                            'ORDER_NUMBER',
                                            'PRICE',
                                            'PHONE',
                                            'NOTE',
                                            'ADDRESS',
											'EMAIL',
											'AUTHOR_NAME',
                                        ),
    	                            array( get_bloginfo( 'name' ),
                                            /*$app->client_name*/'',
                                            $app->product,
                                            /*$app->product_with_qty*/'',
                                            /*$app->order_date_time*/'',
                                            /*$app->order_date*/'',
                                            /*$app->id*/'',
                                            /*$app->order_total*/'',
                                            /*$app->client_phone*/'',
                                            /*$app->order_note*/'',
                                            /*$app->client_address*/'',
											$app->client_email,
											$app->author_name,
                                        ),
                                    $this->get_summary() );
    	
    	    $description = str_replace( array( 'SITE_NAME',
                                                'CLIENT', 
                                                'PRODUCT_NAME', 
                                                'PRODUCT_WITH_QTY',
                                                'ORDER_DATE_TIME', 
                                                'ORDER_DATE', 
                                                'ORDER_NUMBER', 
                                                'PRICE', 
                                                'PHONE', 
                                                'NOTE', 
                                                'ADDRESS', 
												'EMAIL',
												'AUTHOR_NAME',
                                            ),
    	                                array( get_bloginfo( 'name' ), 
                                                /*$app->client_name*/'', 
                                                $app->product, 
                                                /*$app->product_with_qty*/'', 
                                                /*$app->order_date_time*/'', 
                                                /*$app->order_date*/'', 
                                                /*$app->id*/'',
                                                /*$app->order_total*/'',
                                                /*$app->client_phone*/'',
                                                /*$app->order_note*/'',
                                                /*$app->client_address*/'',
												$app->client_email,
												$app->author_name,
                                            ),
                                        $this->get_description() );

            $location       = apply_filters( "bkap_google_event_location", $location, $app );
            $summary        = apply_filters( "bkap_google_event_summary", $summary, $app );
			$description    = apply_filters( "bkap_google_event_description", $description, $app );
			
			$tz_string      = bkapsg_get_timezone_string();

    	
    	    // Find time difference from Greenwich as GCal asks UTC
    	    if ( !current_time( 'timestamp' ) ) {
    	        $tdif = 0;
    	    } else {
    	        $tdif = current_time( 'timestamp' ) - time();
    	    }
    	
    	    if( $app->start_time == "" && $app->end_time == "" ) {
    	        $start = new BKAP_Google_Service_Calendar_EventDateTime();
    	        $start->setDate( date( "Y-m-d", strtotime( $app->start ) ) );
    	
    	        $end = new BKAP_Google_Service_Calendar_EventDateTime();
    	        $end->setDate( date( "Y-m-d", strtotime( $app->end ) ) );
    	    } else if( $app->end_time == "" ) {
    	        $start = new BKAP_Google_Service_Calendar_EventDateTime();
    	        $start->setDateTime( date( "Y-m-d\TH:i:s\Z", strtotime( $app->start . " " . $app->start_time ) - $tdif ) );
    	
    	        $end = new BKAP_Google_Service_Calendar_EventDateTime();
    	        $end->setDateTime( date( "Y-m-d\TH:i:s\Z", strtotime( '+30 minutes', strtotime( $app->end . " " . $app->start_time ) )  - $tdif ) );
    	    } else {
    	        $start = new BKAP_Google_Service_Calendar_EventDateTime();
    	        // $start->setDateTime( date( "Y-m-d\TH:i:s\Z", strtotime( $app->start . " " . $app->start_time ) - $tdif ) );
				$start->setDateTime( sgbkap_get_date_as_per_timezone( $app->start . " " . $app->start_time, $tz_string ) );
				//$start->setTimeZone( 'Europe/Zurich' );
    	        $end = new BKAP_Google_Service_Calendar_EventDateTime();
				// $end->setDateTime( date( "Y-m-d\TH:i:s\Z", strtotime( $app->end . " " . $app->end_time ) - $tdif ) );
				$end->setDateTime( sgbkap_get_date_as_per_timezone( $app->end . " " . $app->end_time, $tz_string ) );
				//$end->setTimeZone( 'Europe/Zurich' );
			}

			//var_dump( date( "Y-m-d\TH:i:s\Z", strtotime( $app->end . " " . $app->end_time ) - $tdif ) );
			
			//$ddddd = sgbkap_get_timestamp_as_per_timezone( $app->start . " " . $app->start_time, 'Europe/Zurich' );
			//echo "<pre>"; print_r( $ddddd ); echo "<pre>";
			
			/* echo "<pre>"; print_r( $app ); echo "<pre>";

			echo "<pre>"; print_r( $start ); echo "<pre>";
			echo "<pre>"; print_r( $end ); echo "<pre>";
			
			var_dump( date( "Y-m-d\TH:i:s\Z", strtotime( $app->start . " " . $app->start_time ) - $tdif ) );
			var_dump( sgbkap_get_timestamp_as_per_timezone( $app->start . " " . $app->start_time, 'Europe/Zurich' ) );
			exit(); */
    	
    	    $email = $app->client_email;
    	    $attendee1 = new BKAP_Google_Service_Calendar_EventAttendee();
    	    $attendee1->setEmail( $email );
    	    $attendees = array( $attendee1 );
    	
    	    $this->event = new BKAP_Google_Service_Calendar_Event();
    	    $this->event->setLocation( $location );
    	    $this->event->setStart( $start );
    	    $this->event->setEnd( $end );
    	    $this->event->setSummary( apply_filters(
    	        'bkap-gcal-set_summary',
    	        $summary
    	    ));
    	    $this->event->setDescription( apply_filters(
    	        'bkap-gcal-set_description',
    	        $description
    	    ));
    	}
    	
    	/**
    	 * Delete event from Gcal when an order is cancelled.
    	 * 
    	 * @param integer $item_id - Item ID
    	 * @param integer $user_id - User ID - to be passed for tour operator calendars
    	 * @param integer $product_id - Product ID, Greater than 0 for product level calendars
    	 * 
    	 * @since 2.6.3
    	 */
    	function delete_event( $event_uid, $user_id, $product_id ) {
    	    	
    	    if ( !$this->connect( $user_id, $product_id ) ) {
    	        return false;
    	    }
    	    	
    	    $user          = new WP_User( $user_id );
    	    $calendar_id   = $this->get_selected_calendar( $user_id, $product_id ); // calendar ID
    	    
    	    $event         = $this->service->events->get( $calendar_id, $event_uid );
			$event_status  = $event->status;
    	    if ( $event_uid != '' && $calendar_id != '' && $event_status != "cancelled" ) {
                $deletedEvent = $this->service->events->delete( $calendar_id, $event_uid );
    	    }
	    }
    	     
    	/**
    	 * Inserts a booking to the selected calendar as event
    	 * 
    	 * @param array $event_details - Details such as start & end dates, time, product, qty etc.
    	 * @param integer $event_id - Item ID
    	 * @param integer $user_id - Passed for tour operators
    	 * @param integer $product_id - Passed for product level calendars 
    	 * @param boolean test: True if a Test booking is being created, else false
    	 * @return boolean
    	 * 
    	 * @since 2.6
    	 */
    	public function insert_event( $event_details, $event_id, $user_id, $product_id = 0, $test = false ) {
    		if ( !$this->connect( $user_id, $product_id ) ) {
    	        return false;
    	    }
    	    global $wpdb;
    	    
    	    if( isset( $user_id ) ) {
    	        $address_1     = get_user_meta( $user_id, 'shipping_address_1' );
    	        $address_2     = get_user_meta( $user_id, 'shipping_address_2' );
    	        $first_name    = get_user_meta( $user_id, 'shipping_first_name' );
    	        $last_name     = get_user_meta( $user_id, 'shipping_last_name' );
    	        $phone         = get_user_meta( $user_id, 'billing_phone' );
    	        $city          = get_user_meta( $user_id, 'shipping_city' );
    	    } else {
    	        $address_1     = "";
    	        $address_2     = "";
    	        $first_name    = "";
    	        $last_name     = "";
    	        $phone         = "";
    	        $city          = "";
    	    }
    	    	
    	    if ( $test ) {
    	        $bkap              = new stdClass();
    	        $bkap->start       = date( 'Y-m-d', $this->local_time );
    	        $bkap->end         = date( 'Y-m-d', $this->local_time );
    	        $bkap->start_time  = date( "H:i:s", $this->local_time + 600 );
    	        $bkap->end_time    = date( 'H:i:s', $this->local_time + 2400 );
    	        $client_email      = get_user_meta( $user_id, 'billing_email' );

    	        if ( isset( $client_email[ 0 ] ) ) {
    	            $bkap->client_email = $client_email[ 0 ];
    	        } else {
    	            $bkap->client_email = '';
    	        }
    	        if( isset( $first_name[ 0 ] ) && isset( $last_name[ 0 ] ) ) {
    	            $bkap->client_name = $first_name[ 0 ] . " " . $last_name[ 0 ];
    	        } else {
    	            $bkap->client_name = "";
    	        }
    	        if( isset( $address_1[ 0 ] ) && isset( $address_2[ 0 ] ) ) {
    	            $bkap->client_address = $address_1[ 0 ] . " " . $address_2[ 0 ];
    	        } else {
    	            $bkap->client_address = "";
    	        }
    	
    	        if( isset( $city[ 0 ] ) ) {
    	            $bkap->client_city = __( $city[ 0 ], 'woocommerce-booking');
    	        } else {
    	            $bkap->client_city = "";
    	        }
    	
    	        if( isset( $phone[ 0 ] ) ) {
    	            $bkap->client_phone = $phone[ 0 ];
    	        } else {
    	            $bkap->client_phone = '';
    	        }
    	        $bkap->order_note          = "";
    	        $bkap->order_total         = "";
    	        $bkap->product             = "";
    	        $bkap->product_with_qty    = "";
    	        $bkap->order_date_time     = "";
    	        $bkap->order_date          = "";
				$bkap->id                  = "";
				$bkap->author_name         = '';
    	    } else {
    	        if ( isset( $event_details[ 'hidden_booking_date' ] ) && $event_details[ 'hidden_booking_date' ] != '' ) {
    	            
                    $booking_date = $event_details[ 'hidden_booking_date' ];
    	            
    	            $bkap          = new stdClass();
    	            $bkap->start   = date( 'Y-m-d', strtotime( $booking_date ) );
    	            
    	            if ( isset( $event_details[ 'hidden_checkout_date' ] ) && $event_details[ 'hidden_checkout_date' ] != '' ) {
                        $checkout_date = $event_details[ 'hidden_checkout_date' ];
    	            } else {
                        $checkout_date = $event_details[ 'hidden_booking_date' ];
    	            }

    	            $bkap->end = date( 'Y-m-d', strtotime( $checkout_date ) );
    	            
                    if ( isset( $event_details[ 'time_slot' ] ) && $event_details[ 'time_slot' ] != '' ) {
    	                
                        $timeslot  = explode( " - ", $event_details[ 'time_slot' ] );
    	                $from_time = date( "H:i", strtotime( $timeslot[ 0 ] ) );

    	                if ( isset( $timeslot[ 1 ] ) && $timeslot[ 1 ] != '' ) {
    	                    $to_time           = date( "H:i", strtotime( $timeslot[ 1 ] ) );
    	                    $bkap->end_time    = $to_time;
    	                } else {
    	                    $bkap->end_time    = '00:00';
    	                    $bkap->end         = date( 'Y-m-d', strtotime( $event_details[ 'hidden_booking_date' ] . '+1 day' ) );
    	                }

    	                $bkap->start_time = $from_time;
    	            } else if( isset( $event_details[ 'duration_time_slot' ] ) && $event_details[ 'duration_time_slot' ] != '' ) {
                        // duration_time_slot = 10:00 - 12:00
                        $timeslot  = explode( " - ", $event_details[ 'duration_time_slot' ] );

                        if ( isset( $timeslot[ 1 ] ) && $timeslot[ 1 ] != '' ) {
                            $bkap->end_time    = $timeslot[ 1 ];
                        } else {
                            $bkap->end_time    = '00:00';
                            $bkap->end         = date( 'Y-m-d', strtotime( $event_details[ 'hidden_booking_date' ] . '+1 day' ) );
                        }
                        $bkap->start_time = $timeslot[ 0 ];

                    } else {
    	                $bkap->start_time  = "";
    	                $bkap->end_time    = "";
    	            }
    	             
    	            $bkap->client_email    = get_option( 'admin_email' );
    	            $product               = get_the_title( $event_details[ 'product_id' ] );
					$bkap->product         = $product;
					$bkap->author_name     = $event_details[ 'author_name' ];
    	        }
    	    }
            
    	    // Create Event object and set parameters
    	    $this->set_event_parameters( $bkap );
    	    // Insert event
    	    try {
    	    	
    	        $createdEvent = $this->service->events->insert( $this->get_selected_calendar( $user_id, $product_id ), $this->event );
    	        
    	        $uid = $createdEvent->iCalUID;

                return $uid;
    	    } catch ( Exception $e ) {
    	        $this->log( "Insert went wrong: " . $e->getMessage() );
    	        return false;
    	    }
    	}
    	
    	/**
    	 * Used to log messages in the bkap-log file.
    	 * 
    	 * @param string $message
    	 * @since 2.6
    	 */
    	function log( $message = '' ) {
    	    if ( $message ) {
    	        $to_put = '<b>['. date_i18n( $this->datetime_format, $this->local_time ) .']</b> '. $message;
    	        // Prevent multiple messages with same text and same timestamp
    	        if ( !file_exists( $this->log_file ) || strpos( @file_get_contents( $this->log_file ), $to_put ) === false )
    	            @file_put_contents( $this->log_file, $to_put . chr(10). chr(13), FILE_APPEND );
    	    }
    	}
    	
    	/**
    	 * Build GCal url for GCal Button. It requires UTC time.
    	 * 
    	 * @param object $bkap - Contains booking details like start date, end date, product, qty etc.
    	 * @return string
    	 * @since 2.6
    	 */
    	function gcal( $bkap, $user_type ) {
    	    // Find time difference from Greenwich as GCal asks UTC
    	    $summary =  str_replace( array( 'SITE_NAME',
                                            'CLIENT',
                                            'PRODUCT_NAME',
                                            'PRODUCT_WITH_QTY',
                                            'ORDER_DATE_TIME',
                                            'ORDER_DATE',
                                            'ORDER_NUMBER',
                                            'PRICE',
                                            'PHONE',
                                            'NOTE',
                                            'ADDRESS',
                                            'EMAIL'
                                        ), 
    		                        array( get_bloginfo( 'name' ),
                                            $bkap->client_name,
                                            $bkap->product,
                                            $bkap->product_with_qty,
                                            $bkap->order_date_time,
                                            $bkap->order_date,
                                            $bkap->id,
                                            $bkap->order_total,
                                            $bkap->client_phone,
                                            $bkap->order_note,
                                            $bkap->client_address,
                                            $bkap->client_email
                                        ),
                                    $this->get_summary() );
    		
    		$description = str_replace( array( 'SITE_NAME',
                                                'CLIENT',
                                                'PRODUCT_NAME',
                                                'PRODUCT_WITH_QTY',
                                                'ORDER_DATE_TIME',
                                                'ORDER_DATE',
                                                'ORDER_NUMBER',
                                                'PRICE',
                                                'PHONE',
                                                'NOTE',
                                                'ADDRESS',
                                                'EMAIL'
                                            ),
    		                            array( get_bloginfo( 'name' ), 
                                                $bkap->client_name,
                                                $bkap->product,
                                                $bkap->product_with_qty,
                                                $bkap->order_date_time,
                                                $bkap->order_date,
                                                $bkap->id ,
                                                $bkap->order_total,
                                                $bkap->client_phone,
                                                $bkap->order_note,
                                                $bkap->client_address,
                                                $bkap->client_email
                                            ),
                                        $this->get_description() );
    	    
    	    if ( $bkap->start_time == "" && $bkap->end_time == "" ) {
    	        $start = strtotime( $bkap->start );    	    
    	        $end   = strtotime( $bkap->end . "+1 day");
    	        
    	        // Using gmdate instead of get_gmt_from_date as the latter is not working correctly with Timezone Strings
    	        $gmt_start = gmdate( 'Ymd', $start );
    	        $gmt_end   = gmdate( 'Ymd', $end );
    	        
    	    } else if( $bkap->end_time == "" ) {
    	        $start     = strtotime( $bkap->start . " " . $bkap->start_time );
    	        $end       = strtotime( $bkap->end . " " . $bkap->start_time );    	        
    	        $gmt_start = get_gmt_from_date( date( 'Y-m-d H:i:s', $start ), "Ymd\THis\Z" );
    	        $gmt_end   = get_gmt_from_date( date( 'Y-m-d H:i:s', $end ), "Ymd\THis\Z" );
    	    } else {
    	        $start     = strtotime( $bkap->start . " " . $bkap->start_time );
    	        $end       = strtotime( $bkap->end . " " . $bkap->end_time );
                if ( $user_type == "admin" ) {
                    $gmt_start = get_gmt_from_date( date( 'Y-m-d H:i:s', $start ), "Ymd\THis\Z" );
                    $gmt_end   = get_gmt_from_date( date( 'Y-m-d H:i:s', $end ), "Ymd\THis\Z" );    
                } else {

                    if ( isset( $_COOKIE['bkap_timezone_name'] ) && "" != $_COOKIE['bkap_timezone_name'] ) {
                        // This will be the case when order is placed with timezone settings

                        $sstart = new DateTime( date( 'Y-m-d H:i:s', $start ), new DateTimeZone( $_COOKIE['bkap_timezone_name'] ) );
                        $sstart->setTimezone( new DateTimeZone('UTC') );
                        $sstart->format( 'Ymd\THis\Z' );
                        $gmt_start = $sstart->format( 'Ymd\THis\Z' );

                        $eend = new DateTime( date( 'Y-m-d H:i:s', $end ), new DateTimeZone( $_COOKIE['bkap_timezone_name'] ) );
                        $eend->setTimezone( new DateTimeZone('UTC') );
                        $eend->format( 'Ymd\THis\Z' );
                        $gmt_end = $eend->format( 'Ymd\THis\Z' );
                    } else {
                        $gmt_start = get_gmt_from_date( date( 'Y-m-d H:i:s', $start ), "Ymd\THis\Z" );
                        $gmt_end   = get_gmt_from_date( date( 'Y-m-d H:i:s', $end ), "Ymd\THis\Z" ); 
                    }
                }
    	    }
    	    
    	    if ( get_option( 'bkap_calendar_event_location' ) != "" ) {
    	        $location = str_replace( array( 'ADDRESS', 'CITY' ), array( $bkap->client_address, $bkap->client_city ), get_option( 'bkap_calendar_event_location' ) );
    	    } else {
    	        $location = get_bloginfo( 'description' );
    	    }
    	    
    	    $param = array(
    	        'action'   => 'TEMPLATE',
    	        'text'     => $summary,
    	        'dates'    => $gmt_start . "/" . $gmt_end,
    	        'location' => $location,
    	        'details'  => $description
    	    );

    	    return esc_url( add_query_arg( array( $param, $start, $end ),
    	        'http://www.google.com/calendar/event'
            ) );
    	}
    }
}