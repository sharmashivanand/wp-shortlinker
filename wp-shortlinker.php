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


add_filter( 'the_content', 'wpsl_frontend_shortlinks_to_permalinks', 99 );


/**
 * Render stored WordPress shortlinks as configured permalinks on the front end.
 *
 * @param string $content Post content.
 * @return string
 */
function wpsl_frontend_shortlinks_to_permalinks( $content ) {
	if ( is_admin() || '' === $content || false === stripos( $content, 'href' ) ) {
		return $content;
	}

	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $content;
	}

	$processor = new WP_HTML_Tag_Processor( $content );

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		$href = $processor->get_attribute( 'href' );

		if ( ! is_string( $href ) || '' === $href ) {
			continue;
		}

		$permalink = wpsl_get_permalink_from_shortlink( $href );

		if ( false !== $permalink ) {
			$processor->set_attribute( 'href', $permalink );
		}
	}

	return $processor->get_updated_html();
}

/**
 * Convert a same-site WordPress query shortlink to the post permalink.
 *
 * Supports:
 * - https://example.com/?p=123
 * - https://example.com/blog/?p=123
 * - /blog/?p=123
 * - ?p=123 is intentionally not supported because it is path-relative.
 *
 * @param string $url URL from an href attribute.
 * @return string|false
 */
function wpsl_get_permalink_from_shortlink( $url ) {
	static $cache = array();

	$url = trim( html_entity_decode( $url, ENT_QUOTES, get_bloginfo( 'charset' ) ) );

	if ( '' === $url ) {
		return false;
	}

	$url_parts = wp_parse_url( $url );

	if ( ! is_array( $url_parts ) || empty( $url_parts['query'] ) ) {
		return false;
	}

	if ( ! wpsl_is_current_site_shortlink_base( $url_parts ) ) {
		return false;
	}

	parse_str( $url_parts['query'], $query_vars );

	$post_id = wpsl_get_post_id_from_shortlink_query_vars( $query_vars );

	if ( ! $post_id ) {
		return false;
	}

	if ( ! array_key_exists( $post_id, $cache ) ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			$cache[ $post_id ] = false;
		} else {
			$post_type = get_post_type_object( $post->post_type );

			if ( ! $post_type || ! $post_type->public ) {
				$cache[ $post_id ] = false;
			} else {
				$cache[ $post_id ] = get_permalink( $post );
			}
		}
	}

	if ( empty( $cache[ $post_id ] ) ) {
		return false;
	}

	$permalink = $cache[ $post_id ];

	// Preserve additional query args added after the shortlink.
	unset( $query_vars['p'], $query_vars['page_id'], $query_vars['attachment_id'] );

	if ( ! empty( $query_vars ) ) {
		$permalink = add_query_arg( $query_vars, $permalink );
	}

	// Preserve fragments, e.g. ?p=123#comments.
	if ( ! empty( $url_parts['fragment'] ) ) {
		$permalink .= '#' . $url_parts['fragment'];
	}

	return $permalink;
}

/**
 * Confirm the URL points to the current site's shortlink base.
 *
 * @param array $url_parts Parsed URL parts.
 * @return bool
 */
function wpsl_is_current_site_shortlink_base( $url_parts ) {
	$home_parts = wp_parse_url( home_url( '/' ) );

	if ( ! is_array( $home_parts ) ) {
		return false;
	}

	if ( ! empty( $url_parts['host'] ) ) {
		if ( empty( $home_parts['host'] ) ) {
			return false;
		}

		if ( strtolower( $url_parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return false;
		}

		$url_port  = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : null;
		$home_port = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : null;

		if ( $url_port !== $home_port ) {
			return false;
		}
	}

	$url_path  = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
	$home_path = isset( $home_parts['path'] ) ? $home_parts['path'] : '/';

	return wpsl_normalize_url_path( $url_path ) === wpsl_normalize_url_path( $home_path );
}

/**
 * Extract a post ID from supported WordPress shortlink query vars.
 *
 * @param array $query_vars Query vars.
 * @return int
 */
function wpsl_get_post_id_from_shortlink_query_vars( $query_vars ) {
	$found = array();

	foreach ( array( 'p', 'page_id', 'attachment_id' ) as $query_var ) {
		if ( ! isset( $query_vars[ $query_var ] ) || is_array( $query_vars[ $query_var ] ) ) {
			continue;
		}

		$value = (string) $query_vars[ $query_var ];

		if ( '' === $value || ! ctype_digit( $value ) ) {
			return 0;
		}

		$found[] = (int) $value;
	}

	if ( 1 !== count( $found ) || $found[0] <= 0 ) {
		return 0;
	}

	return $found[0];
}

/**
 * Normalize URL paths for current-site base comparison.
 *
 * @param string $path URL path.
 * @return string
 */
function wpsl_normalize_url_path( $path ) {
	$path = is_string( $path ) && '' !== $path ? $path : '/';
	$path = '/' . ltrim( $path, '/' );
	$path = untrailingslashit( $path );

	return '' === $path ? '/' : $path;
}