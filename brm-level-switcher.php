<?php
/**
 * Plugin Name:       BRM Level Switcher
 * Plugin URI:        https://github.com/blissguy/brm-level-switcher
 * Description:        Dev utility: switch the current user's BricksMembers level straight from the admin bar. Pick a level, the page reloads, and the new level takes effect. Admins only.
 * Version:           1.0.0
 * Author:            Mixbus Marketing
 * Author URI:        https://mixbusmarketing.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Text Domain:       brm-level-switcher
 *
 * @package BRM\LevelSwitcher
 */

declare( strict_types=1 );

namespace BRM\LevelSwitcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability required to see and use the switcher. Filterable so the gate can
 * be loosened/tightened without editing the plugin.
 */
const REQUIRED_CAP = 'manage_options';

/** admin-post action slug used for the switch request. */
const ACTION = 'brm_ls_switch_level';

/** Nonce action name. */
const NONCE_ACTION = 'brm_ls_switch_level';

/**
 * Whether the BricksMembers public API we rely on is available.
 */
function brm_api_available(): bool {
	return function_exists( 'brm_get_all_levels' )
		&& function_exists( 'brm_get_user_levels' )
		&& function_exists( 'brm_set_user_levels' );
}

/**
 * Whether the current user is allowed to use the switcher.
 */
function user_can_switch(): bool {
	/**
	 * Filter the capability required to use the level switcher.
	 *
	 * @param string $cap Capability slug. Default 'manage_options'.
	 */
	$cap = (string) apply_filters( 'brm_ls_required_cap', REQUIRED_CAP );

	return is_user_logged_in() && current_user_can( $cap );
}

/**
 * Build the admin bar nodes.
 *
 * @param \WP_Admin_Bar $bar Admin bar instance.
 */
function admin_bar_menu( \WP_Admin_Bar $bar ): void {
	if ( ! user_can_switch() || ! brm_api_available() ) {
		return;
	}

	$user_id = get_current_user_id();

	$levels = brm_get_all_levels();
	if ( ! is_array( $levels ) || empty( $levels ) ) {
		// No levels defined yet: show a disabled hint rather than an empty menu.
		$bar->add_node(
			array(
				'id'    => 'brm-level-switcher',
				'title' => esc_html__( 'BRM Level: none defined', 'brm-level-switcher' ),
				'meta'  => array( 'title' => esc_attr__( 'No BricksMembers levels exist yet', 'brm-level-switcher' ) ),
			)
		);
		return;
	}

	$active_ids = array_map( 'intval', (array) brm_get_user_levels( $user_id ) );

	// Resolve a readable label for the current state.
	$current_label = __( 'No level', 'brm-level-switcher' );
	if ( ! empty( $active_ids ) ) {
		$names = array();
		foreach ( $levels as $level ) {
			if ( in_array( (int) $level->id, $active_ids, true ) ) {
				$names[] = $level->name;
			}
		}
		if ( ! empty( $names ) ) {
			$current_label = implode( ', ', $names );
		}
	}

	// Parent node.
	$bar->add_node(
		array(
			'id'    => 'brm-level-switcher',
			'title' => sprintf(
				'<span class="ab-icon dashicons dashicons-groups" aria-hidden="true"></span><span class="ab-label">%s</span>',
				esc_html( sprintf( /* translators: %s: current level name(s) */ __( 'BRM Level: %s', 'brm-level-switcher' ), $current_label ) )
			),
			'meta'  => array( 'title' => esc_attr__( 'Switch your BricksMembers level', 'brm-level-switcher' ) ),
		)
	);

	// "No level" option (clears all levels).
	$bar->add_node(
		array(
			'parent' => 'brm-level-switcher',
			'id'     => 'brm-level-switcher-none',
			'title'  => switch_link_title( __( 'No level', 'brm-level-switcher' ), empty( $active_ids ) ),
			'href'   => switch_url( 0 ),
		)
	);

	// One child per level.
	foreach ( $levels as $level ) {
		$level_id  = (int) $level->id;
		$is_active = in_array( $level_id, $active_ids, true );

		$bar->add_node(
			array(
				'parent' => 'brm-level-switcher',
				'id'     => 'brm-level-switcher-' . $level_id,
				'title'  => switch_link_title( (string) $level->name, $is_active ),
				'href'   => switch_url( $level_id ),
			)
		);
	}
}

/**
 * Build a checkmarked title for a menu row.
 */
function switch_link_title( string $label, bool $is_active ): string {
	$check = $is_active ? '<span class="brm-ls-check" aria-hidden="true">&#10003;</span> ' : '<span class="brm-ls-check brm-ls-check--empty" aria-hidden="true"></span> ';
	return $check . esc_html( $label );
}

/**
 * Build a nonce-protected admin-post URL for switching to a level.
 *
 * @param int $level_id Level ID, or 0 to clear.
 */
function switch_url( int $level_id ): string {
	$url = add_query_arg(
		array(
			'action'   => ACTION,
			'level_id' => $level_id,
			'redirect' => current_url(),
		),
		admin_url( 'admin-post.php' )
	);

	return wp_nonce_url( $url, NONCE_ACTION . '_' . $level_id, '_brm_ls_nonce' );
}

/**
 * The URL of the page the admin bar is currently rendering on, so we can
 * return the user to it after switching.
 */
function current_url(): string {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	if ( $host && $uri ) {
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	return wp_get_referer() ?: home_url( '/' );
}

/**
 * Handle the switch request: verify, set level, redirect back.
 */
function handle_switch(): void {
	if ( ! user_can_switch() ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'brm-level-switcher' ), '', array( 'response' => 403 ) );
	}

	$level_id = isset( $_GET['level_id'] ) ? absint( wp_unslash( $_GET['level_id'] ) ) : 0;

	$nonce = isset( $_GET['_brm_ls_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_brm_ls_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, NONCE_ACTION . '_' . $level_id ) ) {
		wp_die( esc_html__( 'Security check failed. Please try again.', 'brm-level-switcher' ), '', array( 'response' => 403 ) );
	}

	if ( brm_api_available() ) {
		// Replace mode: a single level (or none) becomes the user's only level.
		$new_levels = $level_id > 0 ? array( $level_id ) : array();
		brm_set_user_levels( get_current_user_id(), $new_levels );
	}

	// Redirect back to where the user came from.
	$redirect = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : '';
	if ( ! $redirect ) {
		$redirect = wp_get_referer() ?: home_url( '/' );
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Inline CSS to keep the checkmark column aligned in the dropdown.
 */
function admin_bar_styles(): void {
	if ( ! user_can_switch() ) {
		return;
	}
	?>
	<style id="brm-ls-styles">
		#wpadminbar #wp-admin-bar-brm-level-switcher .brm-ls-check { display:inline-block; width:1em; color:#46b450; font-weight:700; }
		#wpadminbar #wp-admin-bar-brm-level-switcher .brm-ls-check--empty { color:transparent; }
		#wpadminbar #wp-admin-bar-brm-level-switcher > .ab-item .ab-icon::before { top:2px; }
	</style>
	<?php
}

add_action( 'admin_bar_menu', __NAMESPACE__ . '\\admin_bar_menu', 100 );
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\handle_switch' );
add_action( 'wp_head', __NAMESPACE__ . '\\admin_bar_styles' );
add_action( 'admin_head', __NAMESPACE__ . '\\admin_bar_styles' );
