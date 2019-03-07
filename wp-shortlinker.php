<?php
/**
 *
 * Plugin Name: WP Shortlinker
 * Plugin URI:  https://www.converticacommerce.com
 * Description: WP Shortlinker changes the internal links to use shortlinks instead of permalinks inside the WordPress editor.
 * Version:     1.0.1
 * Author:      Shivanand Sharma
 * Author URI:  https://www.converticacommerce.com
 * Text Domain: shortlinker
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 */
 
/**
 *
 * TODO:
 *
 * allow permalinks to be replaced by shortlinks
 *
 */

add_filter( 'plugin_action_links_' . plugin_basename(__FILE__) , 'wpsl_add_action_links' );

function wpsl_add_action_links( $links ) {
    $sl_links = array(
		'<a href="https://www.binaryturf.com/contact">' . __( 'Support', 'shortlinker' ) . '</a>',
		'<a href="https://www.binaryturf.com?item_name=Donation%20for%20WP%20Shortlinker%20Plugin&cmd=_donations&currency_code=USD&lc=US">' . __( 'Donate', 'shortlinker' ) . '</a>',
    );
    
    return array_merge( $links, $sl_links );
}

add_filter( 'wp_link_query', 'wpsl_link_query', 10, 2 );

function wpsl_link_query( $results, $query ) {
	// Note: http://php.net/manual/en/control-structures.foreach.php#111688
	foreach ( $results as &$result ) {
		$result['permalink'] = wp_get_shortlink( $result['ID'] );
	}

	return $results;
}