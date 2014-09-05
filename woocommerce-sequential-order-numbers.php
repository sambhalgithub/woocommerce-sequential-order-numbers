<?php
/**
 * Plugin Name: WooCommerce Sequential Order Numbers
 * Plugin URI: http://www.skyverge.com/blog/woocommerce-sequential-order-numbers/
 * Description: Provides sequential order numbers for WooCommerce orders
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com
 * Version: 1.3.3
 *
 * Copyright: (c) 2012-2013 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Sequential-Order-Numbers
 * @author    SkyVerge
 * @category  Plugin
 * @copyright Copyright (c) 2012-2013, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Check if WooCommerce is active
if ( ! WC_Seq_Order_Number::is_woocommerce_active() ) {
	return;
}

/**
 * The WC_Seq_Order_Number global object
 * @name $wc_seq_order_number
 * @global WC_Seq_Order_Number $GLOBALS['wc_seq_order_number']
 */
$GLOBALS['wc_seq_order_number'] = new WC_Seq_Order_Number();

class WC_Seq_Order_Number {

	/** version number */
	const VERSION = "1.3.3";

	/** version option name */
	const VERSION_OPTION_NAME = "woocommerce_seq_order_number_db_version";

	/** minimum required wc version */
	const MINIMUM_WC_VERSION = '2.1';


	/**
	 * Construct the plugin
	 *
	 * @since 1.3.2
	 */
	public function __construct() {

		add_action( 'plugins_loaded', array( $this, 'initialize' ) );
		add_action( 'init',           array( $this, 'load_translation' ) );
	}


	/**
	 * Initialize the plugin, bailing if any required conditions are not met,
	 * including minimum WooCommerce version
	 *
	 * @since 1.3.2
	 */
	public function initialize() {

		if ( ! $this->minimum_wc_version_met() ) {
			// halt functionality
			return;
		}

		// set the custom order number on the new order.  we hook into wp_insert_post for orders which are created
		//  from the frontend, and we hook into woocommerce_process_shop_order_meta for admin-created orders
		add_action( 'wp_insert_post',                      array( $this, 'set_sequential_order_number' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'set_sequential_order_number' ), 10, 2 );

		// return our custom order number for display
		add_filter( 'woocommerce_order_number',            array( $this, 'get_order_number' ), 10, 2);

		// order tracking page search by order number
		add_filter( 'woocommerce_shortcode_order_tracking_order_id', array( $this, 'find_order_by_order_number' ) );

		// WC Subscriptions support: prevent unnecessary order meta from polluting parent renewal orders, and set order number for subscription orders
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'subscriptions_remove_renewal_order_meta' ), 10, 4 );
		add_action( 'woocommerce_subscriptions_renewal_order_created',    array( $this, 'subscriptions_set_sequential_order_number' ), 10, 4 );

		if ( is_admin() ) {
			add_filter( 'request',                              array( $this, 'woocommerce_custom_shop_order_orderby' ), 20 );
			add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'custom_search_fields' ) );

			// sort by underlying _order_number on the Pre-Orders table
			add_filter( 'wc_pre_orders_edit_pre_orders_request', array( $this, 'custom_orderby' ) );
			add_filter( 'wc_pre_orders_search_fields',           array( $this, 'custom_search_fields' ) );
		}

		// Installation
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			$this->install();
		}
	}


	/**
	 * Load Translations
	 *
	 * @since 1.3.3
	 */
	public function load_translation() {

		// localization
		load_plugin_textdomain( 'woocommerce-sequential-order-numbers', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages' );
	}


	/**
	 * Search for an order with order_number $order_number
	 *
	 * @param string $order_number order number to search for
	 *
	 * @return int post_id for the order identified by $order_number, or 0
	 */
	public function find_order_by_order_number( $order_number ) {

		// search for the order by custom order number
		$query_args = array(
			'numberposts' => 1,
			'meta_key'    => '_order_number',
			'meta_value'  => $order_number,
			'post_type'   => 'shop_order',
			'post_status' => 'publish',
			'fields'      => 'ids',
		);

		$query_args = self::backport_order_status_query_args( $query_args );

		list( $order_id ) = get_posts( $query_args );

		// order was found
		if ( $order_id !== null ) {
			return $order_id;
		}

		// if we didn't find the order, then it may be that this plugin was disabled and an order was placed in the interim
		$order = self::wc_get_order( $order_number );
		if ( $order->order_number ) {
			// _order_number was set, so this is not an old order, it's a new one that just happened to have post_id that matched the searched-for order_number
			return 0;
		}

		return $order->id;
	}


	/**
	 * Set the _order_number field for the newly created order
	 *
	 * @param int $post_id post identifier
	 * @param object $post post object
	 */
	public function set_sequential_order_number( $post_id, $post ) {
		global $wpdb;

		if ( 'shop_order' == $post->post_type && 'auto-draft' != $post->post_status ) {
			$order_number = get_post_meta( $post_id, '_order_number', true );
			if ( "" == $order_number ) {

				// attempt the query up to 3 times for a much higher success rate if it fails (due to Deadlock)
				$success = false;
				for ( $i = 0; $i < 3 && ! $success; $i++ ) {
					// this seems to me like the safest way to avoid order number clashes
					$query = $wpdb->prepare( "
						INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
						SELECT %d, '_order_number', IF( MAX( CAST( meta_value as UNSIGNED ) ) IS NULL, 1, MAX( CAST( meta_value as UNSIGNED ) ) + 1 )
							FROM {$wpdb->postmeta}
							WHERE meta_key='_order_number'",
						$post_id );

					$success = $wpdb->query( $query );
				}
			}
		}
	}


	/**
	 * Filter to return our _order_number field rather than the post ID,
	 * for display.
	 *
	 * @param string $order_number the order id with a leading hash
	 * @param WC_Order $order the order object
	 *
	 * @return string custom order number, with leading hash
	 */
	public function get_order_number( $order_number, $order ) {

		if ( $order->order_number ) {
			return '#' . $order->order_number;
		}

		return $order_number;
	}


	/** Admin filters ******************************************************/


	/**
	 * Admin order table orderby ID operates on our meta _order_number
	 *
	 * @param array $vars associative array of orderby parameteres
	 *
	 * @return array associative array of orderby parameteres
	 */
	public function woocommerce_custom_shop_order_orderby( $vars ) {
		global $typenow, $wp_query;
		if ( 'shop_order' == $typenow ) {
			return $vars;

		}

		return $this->custom_orderby( $vars );
	}


	/**
	 * Mofifies the given $args argument to sort on our meta integral _order_number
	 *
	 * @since 1.3
	 * @param array $vars associative array of orderby parameteres
	 * @return array associative array of orderby parameteres
	 */
	public function custom_orderby( $args ) {
		// Sorting
		if ( isset( $args['orderby'] ) && 'ID' == $args['orderby'] ) {
			$args = array_merge( $args, array(
				'meta_key' => '_order_number',  // sort on numerical portion for better results
				'orderby'  => 'meta_value_num',
			) );
		}

		return $args;
	}


	/**
	 * Add our custom _order_number to the set of search fields so that
	 * the admin search functionality is maintained
	 *
	 * @param array $search_fields array of post meta fields to search by
	 *
	 * @return array of post meta fields to search by
	 */
	public function custom_search_fields( $search_fields ) {

		array_push( $search_fields, '_order_number' );

		return $search_fields;
	}


	/** 3rd Party Plugin Support ******************************************************/


	/**
	 * Sets an order number on a subscriptions-created order
	 *
	 * @since 1.3
	 *
	 * @param WC_Order $renewal_order the new renewal order object
	 * @param WC_Order $original_order the original order object
	 * @param int $product_id the product post identifier
	 * @param string $new_order_role the role the renewal order is taking, one of 'parent' or 'child'
	 */
	public function subscriptions_set_sequential_order_number( $renewal_order, $original_order, $product_id, $new_order_role ) {
		$order_post = get_post( $renewal_order->id );
		$this->set_sequential_order_number( $order_post->ID, $order_post );
	}


	/**
	 * Don't copy over order number meta when creating a parent or child renewal order
	 *
	 * @since 1.3
	 *
	 * @param array $order_meta_query query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return string
	 */
	public function subscriptions_remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		$order_meta_query .= " AND meta_key NOT IN ( '_order_number' )";

		return $order_meta_query;
	}


	/** Helper Methods ******************************************************/


	/**
	 * Checks if WooCommerce is active
	 *
	 * @since  1.3
	 * @return bool true if WooCommerce is active, false otherwise
	 */
	public static function is_woocommerce_active() {

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
		}

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
	}


	/** Compatibility Methods ******************************************************/


	/**
	 * Get the WC Order instance for a given order ID or order post
	 *
	 * Introduced in WC 2.2 as part of the Order Factory so the 2.1 version is
	 * not an exact replacement.
	 *
	 * If no param is passed, it will use the global post. Otherwise pass an
	 * the order post ID or post object.
	 *
	 * @since 1.3.2
	 * @param bool|int|string|\WP_Post $the_order
	 * @return bool|\WC_Order
	 */
	public static function wc_get_order( $the_order = false ) {

		if ( self::is_wc_version_gte_2_2() ) {

			return wc_get_order( $the_order );

		} else {

			global $post;

			if ( false === $the_order ) {

				$order_id = $post->ID;

			} elseif ( $the_order instanceof WP_Post ) {

				$order_id = $the_order->ID;

			} elseif ( is_numeric( $the_order ) ) {

				$order_id = $the_order;
			}

			return new WC_Order( $order_id );
		}
	}


	/**
	 * Transparently backport the `post_status` WP Query arg used by WC 2.2
	 * for order statuses to the `shop_order_status` taxonomy query arg used by
	 * WC 2.1
	 *
	 * @since 1.3.2
	 * @param array $args WP_Query args
	 * @return array
	 */
	public static function backport_order_status_query_args( $args ) {

		if ( ! self::is_wc_version_gte_2_2() ) {

			// convert post status arg to taxonomy query compatible with WC 2.1
			if ( ! empty( $args['post_status'] ) ) {

				$order_statuses = array();

				foreach ( (array) $args['post_status'] as $order_status ) {

					$order_statuses[] = str_replace( 'wc-', '', $order_status );
				}

				$args['post_status'] = 'publish';

				$tax_query = array(
					array(
						'taxonomy' => 'shop_order_status',
						'field'    => 'slug',
						'terms'    => $order_statuses,
						'operator' => 'IN',
					)
				);

				$args['tax_query'] = array_merge( ( isset( $args['tax_query'] ) ? $args['tax_query'] : array() ), $tax_query );
			}
		}

		return $args;
	}


	/**
	 * Helper method to get the version of the currently installed WooCommerce
	 *
	 * @since 1.3.2
	 * @return string woocommerce version number or null
	 */
	private static function get_wc_version() {

		return defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;
	}


	/**
	 * Returns true if the installed version of WooCommerce is 2.2 or greater
	 *
	 * @since 1.3.2
	 * @return boolean true if the installed version of WooCommerce is 2.2 or greater
	 */
	public static function is_wc_version_gte_2_2() {
		return self::get_wc_version() && version_compare( self::get_wc_version(), '2.2', '>=' );
	}


	/**
	 * Perform a minimum WooCommerce version check
	 *
	 * @since 1.3.2
	 * @return boolean true if the required version is met, false otherwise
	 */
	private function minimum_wc_version_met() {

		// if a plugin defines a minimum WC version, render a notice and skip loading the plugin
		if ( defined( 'self::MINIMUM_WC_VERSION' ) && version_compare( self::get_wc_version(), self::MINIMUM_WC_VERSION, '<' ) ) {
			if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! has_action( 'admin_notices', array( $this, 'render_update_notices' ) ) ) {
				add_action( 'admin_notices', array( $this, 'render_update_notices' ) );
			}
			return false;
		}

		return true;
	}


	/**
	 * Render a notice to update WooCommerce if needed
	 *
	 * @since 1.3.2
	 */
	public function render_update_notices() {

		echo '<div class="error"><p>The following plugin is inactive because it requires a newer version of WooCommerce:</p><ul>';

		printf( '<li>%s requires WooCommerce %s or newer</li>', 'Sequential Order Numbers', self::MINIMUM_WC_VERSION );

		echo '</ul><p>Please <a href="' . admin_url( 'update-core.php' ) . '">update WooCommerce&nbsp;&raquo;</a></p></div>';

	}


	/** Lifecycle methods ******************************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
	 */
	private function install() {
		$installed_version = get_option( WC_Seq_Order_Number::VERSION_OPTION_NAME );

		if ( ! $installed_version ) {
			// initial install, set the order number for all existing orders to the post id
			$orders = get_posts( array( 'numberposts' => '', 'post_type' => 'shop_order', 'nopaging' => true ) );
			if ( is_array( $orders ) ) {
				foreach( $orders as $order ) {
					if ( '' == get_post_meta( $order->ID, '_order_number', true ) ) {
						add_post_meta( $order->ID, '_order_number', $order->ID );
					}
				}
			}
		}

		if ( $installed_version != WC_Seq_Order_Number::VERSION ) {
			$this->upgrade( $installed_version );

			// new version number
			update_option( WC_Seq_Order_Number::VERSION_OPTION_NAME, WC_Seq_Order_Number::VERSION );
		}
	}


	/**
	 * Run when plugin version number changes
	 */
	private function upgrade( $installed_version ) {
		// upgrade code goes here
	}
}
