<?php
/**
 * Plugin Name: WP Shortlinker
 * Plugin URI:  https://www.converticacommerce.com
 * Description: Changes internal links to use WordPress shortlinks inside the editor, while rendering configured permalinks on the front end.
 * Version:     1.1.0
 * Author:      Shivanand Sharma
 * Author URI:  https://www.converticacommerce.com
 * Text Domain: wp-shortlinker
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpsl_add_action_links' );

/**
 * Add plugin action links.
 *
 * @param array $links Existing action links.
 * @return array
 */
function wpsl_add_action_links( $links ) {
	$wpsl_links = array(
		'<a href="' . esc_url( 'https://www.binaryturf.com/contact' ) . '">' . esc_html__( 'Support', 'wp-shortlinker' ) . '</a>',
		'<a href="' . esc_url( 'https://www.binaryturf.com?item_name=Donation%20for%20WP%20Shortlinker%20Plugin&cmd=_donations&currency_code=USD&lc=US' ) . '">' . esc_html__( 'Donate', 'wp-shortlinker' ) . '</a>',
	);

	return array_merge( $links, $wpsl_links );
}

add_filter( 'wp_link_query', 'wpsl_link_query', 10, 2 );

/**
 * Replace internal-link picker permalinks with WordPress shortlinks.
 *
 * @param array $results Link query results.
 * @param array $query   Link query arguments.
 * @return array
 */
function wpsl_link_query( $results, $query ) {
	foreach ( $results as &$result ) {
		if ( empty( $result['ID'] ) ) {
			continue;
		}

		$shortlink = wp_get_shortlink( (int) $result['ID'] );

		if ( '' !== $shortlink ) {
			$result['permalink'] = $shortlink;
		}
	}
	unset( $result );

	return $results;
}

add_filter( 'the_content', 'wpsl_frontend_shortlinks_to_permalinks', 99 );

/**
 * Render stored WordPress shortlinks as configured permalinks on the front end.
 *
 * Only exact, absolute, same-site WordPress shortlinks are converted. Existing
 * pretty permalinks and query URLs with additional parameters are left untouched.
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

		if ( false !== $permalink && $permalink !== $href ) {
			$processor->set_attribute( 'href', $permalink );
		}
	}

	return $processor->get_updated_html();
}

/**
 * Convert an exact same-site WordPress query shortlink to the post permalink.
 *
 * Accepted examples:
 * - https://example.com/?p=123
 * - https://example.com/blog/?p=123
 * - https://example.com/?page_id=123
 * - https://example.com/?attachment_id=123
 *
 * Rejected examples:
 * - /?p=123
 * - ?p=123
 * - https://example.com/post-name/
 * - https://example.com/?p=123&utm_source=newsletter
 * - https://example.com/?p=123&preview=true
 * - http://example.com/?p=123 when home_url() uses https
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

	$post_id = wpsl_get_post_id_from_shortlink_query( $url_parts['query'] );

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

	if ( ! empty( $url_parts['fragment'] ) ) {
		$permalink .= '#' . $url_parts['fragment'];
	}

	return $permalink;
}

/**
 * Confirm the URL points exactly to the current site's shortlink base.
 *
 * This intentionally requires an absolute URL because wp_get_shortlink()
 * returns an absolute URL. Relative URLs are not rewritten.
 *
 * @param array $url_parts Parsed URL parts.
 * @return bool
 */
function wpsl_is_current_site_shortlink_base( $url_parts ) {
	$home_parts = wp_parse_url( home_url( '/' ) );

	if (
		! is_array( $home_parts )
		|| empty( $home_parts['scheme'] )
		|| empty( $home_parts['host'] )
		|| empty( $url_parts['scheme'] )
		|| empty( $url_parts['host'] )
	) {
		return false;
	}

	if ( ! empty( $url_parts['user'] ) || ! empty( $url_parts['pass'] ) ) {
		return false;
	}

	if ( strtolower( $url_parts['scheme'] ) !== strtolower( $home_parts['scheme'] ) ) {
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

	$url_path  = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
	$home_path = isset( $home_parts['path'] ) ? $home_parts['path'] : '/';

	return wpsl_normalize_url_path( $url_path ) === wpsl_normalize_url_path( $home_path );
}

/**
 * Extract a post ID from an exact WordPress shortlink query string.
 *
 * Accepts only:
 * - p=123
 * - page_id=123
 * - attachment_id=123
 *
 * @param string $query Raw query string.
 * @return int
 */
function wpsl_get_post_id_from_shortlink_query( $query ) {
	if ( ! is_string( $query ) || '' === $query ) {
		return 0;
	}

	if ( ! preg_match( '/^(?:p|page_id|attachment_id)=([1-9][0-9]*)$/', $query, $matches ) ) {
		return 0;
	}

	return (int) $matches[1];
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
