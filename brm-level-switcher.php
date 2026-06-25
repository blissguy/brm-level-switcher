<?php
/**
 * Plugin Name:       BRM Level Switcher
 * Plugin URI:        https://github.com/blissguy/brm-level-switcher
 * Description:        Dev utility: switch the current user's BricksMembers level straight from the admin bar. Pick a level, the page reloads, and the new level takes effect. Configurable via a settings page under the BricksMembers menu.
 * Version:           1.1.2
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

/** Capability required to manage settings and the fallback gate for the switcher. */
const REQUIRED_CAP = 'manage_options';

/** admin-post action slug used for the switch request. */
const ACTION = 'brm_ls_switch_level';

/** Nonce action base for switch links. */
const NONCE_ACTION = 'brm_ls_switch_level';

/** Query arg appended to the post-switch redirect to bypass full-page caches once. */
const CACHE_BUST_ARG = 'brm_ls_cb';

/** Option key for plugin settings. */
const OPTION = 'brm_ls_settings';

/** Settings page slug. */
const PAGE_SLUG = 'brm-level-switcher';

/**
 * Default settings.
 *
 * @return array{multi_select:bool,levels:int[],roles:string[]}
 */
function default_settings(): array {
	return array(
		'multi_select' => false,
		'levels'       => array(),            // Empty = show all levels.
		'roles'        => array( 'administrator' ),
	);
}

/**
 * Get merged settings.
 *
 * @return array{multi_select:bool,levels:int[],roles:string[]}
 */
function get_settings(): array {
	$saved = get_option( OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings = array_merge( default_settings(), $saved );

	$settings['multi_select'] = ! empty( $settings['multi_select'] );
	$settings['levels']       = array_values( array_map( 'intval', (array) $settings['levels'] ) );
	$settings['roles']        = array_values( array_map( 'strval', (array) $settings['roles'] ) );

	return $settings;
}

/**
 * Whether the BricksMembers public API we rely on is available.
 */
function brm_api_available(): bool {
	return function_exists( 'brm_get_all_levels' )
		&& function_exists( 'brm_get_user_levels' )
		&& function_exists( 'brm_set_user_levels' );
}

/**
 * All BricksMembers levels (raw), or empty array.
 *
 * @return array<int,object>
 */
function all_levels(): array {
	if ( ! function_exists( 'brm_get_all_levels' ) ) {
		return array();
	}
	$levels = brm_get_all_levels();
	return is_array( $levels ) ? $levels : array();
}

/**
 * Levels that should appear in the switcher, honouring the "levels to show" setting.
 *
 * @return array<int,object>
 */
function visible_levels(): array {
	$levels  = all_levels();
	$allowed = get_settings()['levels'];

	if ( empty( $allowed ) ) {
		return $levels;
	}

	return array_values(
		array_filter(
			$levels,
			static function ( $level ) use ( $allowed ): bool {
				return in_array( (int) $level->id, $allowed, true );
			}
		)
	);
}

/**
 * Whether the current user may use the admin-bar switcher.
 *
 * Visible if the user holds one of the allowed roles, or satisfies the
 * (filterable) capability gate. Administrators always qualify via the cap,
 * so they cannot accidentally lock themselves out of the switcher.
 */
function user_can_switch(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	/**
	 * Filter the capability that always grants access to the switcher.
	 *
	 * @param string $cap Capability slug. Default 'manage_options'.
	 */
	$cap = (string) apply_filters( 'brm_ls_required_cap', REQUIRED_CAP );
	if ( $cap && current_user_can( $cap ) ) {
		return true;
	}

	$allowed_roles = get_settings()['roles'];
	if ( empty( $allowed_roles ) ) {
		return false;
	}

	$user = wp_get_current_user();
	return (bool) array_intersect( $allowed_roles, (array) $user->roles );
}

/* -------------------------------------------------------------------------
 * Admin bar
 * ---------------------------------------------------------------------- */

/**
 * Build the admin bar nodes.
 *
 * @param \WP_Admin_Bar $bar Admin bar instance.
 */
function admin_bar_menu( \WP_Admin_Bar $bar ): void {
	if ( ! user_can_switch() || ! brm_api_available() ) {
		return;
	}

	$multi   = get_settings()['multi_select'];
	$user_id = get_current_user_id();
	$levels  = visible_levels();

	if ( empty( $levels ) ) {
		$bar->add_node(
			array(
				'id'    => 'brm-level-switcher',
				'title' => esc_html__( 'BRM Level: none available', 'brm-level-switcher' ),
				'meta'  => array( 'title' => esc_attr__( 'No BricksMembers levels to switch to', 'brm-level-switcher' ) ),
			)
		);
		return;
	}

	$active_ids = array_map( 'intval', (array) brm_get_user_levels( $user_id ) );

	// Readable label for the current state.
	$current_label = __( 'No level', 'brm-level-switcher' );
	$names         = array();
	foreach ( $levels as $level ) {
		if ( in_array( (int) $level->id, $active_ids, true ) ) {
			$names[] = $level->name;
		}
	}
	if ( ! empty( $names ) ) {
		$current_label = implode( ', ', $names );
	}

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

	// Clear option: "Clear all" in multi-select mode, otherwise "No level".
	$bar->add_node(
		array(
			'parent' => 'brm-level-switcher',
			'id'     => 'brm-level-switcher-none',
			'title'  => switch_link_title(
				$multi ? __( 'Clear all levels', 'brm-level-switcher' ) : __( 'No level', 'brm-level-switcher' ),
				empty( $active_ids )
			),
			'href'   => switch_url( 0 ),
		)
	);

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
	$check = $is_active
		? '<span class="brm-ls-check" aria-hidden="true">&#10003;</span> '
		: '<span class="brm-ls-check brm-ls-check--empty" aria-hidden="true"></span> ';
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
 * The URL of the page the admin bar is currently rendering on.
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
 * Handle the switch request: verify, apply, redirect back.
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
		$user_id = get_current_user_id();
		$multi   = get_settings()['multi_select'];

		if ( 0 === $level_id ) {
			// Clear all levels (both modes).
			brm_set_user_levels( $user_id, array() );
		} elseif ( $multi ) {
			// Toggle the chosen level on/off, preserving the rest.
			$current = array_map( 'intval', (array) brm_get_user_levels( $user_id ) );
			if ( in_array( $level_id, $current, true ) ) {
				$current = array_values( array_diff( $current, array( $level_id ) ) );
			} else {
				$current[] = $level_id;
			}
			brm_set_user_levels( $user_id, $current );
		} else {
			// Replace mode: the chosen level becomes the only level.
			brm_set_user_levels( $user_id, array( $level_id ) );
		}

		// Bust the persistent object cache so the new level shows on the next
		// render instead of waiting out BRM's object-cache TTL. Runs only on the
		// gated, nonce-checked admin switch action.
		//
		// wp_cache_flush() clears the object cache for ALL users, so the next
		// request rebuilds from the DB. That's acceptable for this admin/dev-only
		// tool.
		wp_cache_flush();
	}

	$redirect = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : '';
	if ( ! $redirect ) {
		$redirect = wp_get_referer() ?: home_url( '/' );
	}

	// Append a one-time cache-busting marker so any full-page cache treats the
	// post-switch reload as a miss and renders fresh content for the new level.
	// It is removed from the address bar client-side on load (see strip_cache_bust_arg).
	$redirect = add_query_arg( CACHE_BUST_ARG, (string) time(), $redirect );

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

/**
 * Remove the one-time cache-busting arg from the address bar after the reload.
 *
 * The server already saw the arg (forcing a full-page-cache miss and a fresh
 * render for the new level); this only tidies the client-side URL so the marker
 * isn't bookmarked, shared, or left lingering. No-op when the arg is absent.
 */
function strip_cache_bust_arg(): void {
	if ( ! isset( $_GET[ CACHE_BUST_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL cleanup, no state change.
		return;
	}
	$arg = wp_json_encode( CACHE_BUST_ARG );
	?>
	<script id="brm-ls-cb-clean">
		( function () {
			var strip = function () {
				try {
					var u = new URL( window.location.href );
					if ( u.searchParams.has( <?php echo $arg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe JS output. ?> ) ) {
						u.searchParams.delete( <?php echo $arg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe JS output. ?> );
						window.history.replaceState( null, '', u.toString() );
					}
				} catch ( e ) {}
			};
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', strip );
			} else {
				strip();
			}
		}() );
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

/**
 * Resolve the live BricksMembers top-level menu slug, or '' if not found.
 */
function brm_parent_slug(): string {
	$class = '\\BaselMedia\\BricksMembers\\Admin\\AdminRouteRegistry';
	if ( class_exists( $class ) && method_exists( $class, 'get_top_level_slug' ) ) {
		$slug = (string) call_user_func( array( $class, 'get_top_level_slug' ) );
		if ( '' !== $slug ) {
			return $slug;
		}
	}

	// Fallback: scan the registered top-level menus for a BRM entry.
	global $menu;
	if ( is_array( $menu ) ) {
		foreach ( $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'brm_dashboard' === $slug || 'brm_license_support' === $slug ) {
				return $slug;
			}
		}
	}

	return '';
}

/**
 * Register the settings page under the BRM menu, falling back to Settings.
 */
function register_settings_page(): void {
	$parent = brm_parent_slug();

	if ( '' !== $parent ) {
		add_submenu_page(
			$parent,
			__( 'Level Switcher', 'brm-level-switcher' ),
			__( 'Level Switcher', 'brm-level-switcher' ),
			REQUIRED_CAP,
			PAGE_SLUG,
			__NAMESPACE__ . '\\render_settings_page'
		);
		return;
	}

	add_options_page(
		__( 'BRM Level Switcher', 'brm-level-switcher' ),
		__( 'BRM Level Switcher', 'brm-level-switcher' ),
		REQUIRED_CAP,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/**
 * Register the option and its settings fields.
 */
function register_settings(): void {
	register_setting(
		'brm_ls_group',
		OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => default_settings(),
		)
	);
}

/**
 * Sanitize submitted settings.
 *
 * @param mixed $input Raw input.
 * @return array{multi_select:bool,levels:int[],roles:string[]}
 */
function sanitize_settings( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$out   = default_settings();

	$out['multi_select'] = ! empty( $input['multi_select'] );

	$valid_level_ids = array_map( static fn( $l ) => (int) $l->id, all_levels() );
	$out['levels']   = array_values(
		array_intersect(
			array_map( 'intval', (array) ( $input['levels'] ?? array() ) ),
			$valid_level_ids
		)
	);

	$valid_roles  = array_keys( get_editable_roles() );
	$out['roles'] = array_values(
		array_intersect(
			array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ),
			$valid_roles
		)
	);

	return $out;
}

/**
 * Render the settings page (semantic, accessible markup).
 */
function render_settings_page(): void {
	if ( ! current_user_can( REQUIRED_CAP ) ) {
		return;
	}

	$settings    = get_settings();
	$levels      = all_levels();
	$roles       = get_editable_roles();
	$brm_missing = ! brm_api_available();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'BRM Level Switcher', 'brm-level-switcher' ); ?></h1>

		<?php if ( $brm_missing ) : ?>
			<div class="notice notice-error"><p>
				<?php esc_html_e( 'BricksMembers is not active, so the switcher is disabled until it is enabled.', 'brm-level-switcher' ); ?>
			</p></div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php settings_fields( 'brm_ls_group' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Multi-select mode', 'brm-level-switcher' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[multi_select]" value="1" <?php checked( $settings['multi_select'] ); ?> />
								<?php esc_html_e( 'Allow holding several levels at once (each item toggles on/off).', 'brm-level-switcher' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, picking a level replaces all others (single-level switch).', 'brm-level-switcher' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Levels to show', 'brm-level-switcher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Levels to show in the switcher', 'brm-level-switcher' ); ?></legend>
								<?php if ( empty( $levels ) ) : ?>
									<p class="description"><?php esc_html_e( 'No BricksMembers levels found.', 'brm-level-switcher' ); ?></p>
								<?php else : ?>
									<?php foreach ( $levels as $level ) : ?>
										<label style="display:block;margin:.25em 0;">
											<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[levels][]" value="<?php echo esc_attr( (string) (int) $level->id ); ?>" <?php checked( in_array( (int) $level->id, $settings['levels'], true ) ); ?> />
											<?php echo esc_html( $level->name ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Tick the levels to offer in the dropdown. Leave all unticked to show every level.', 'brm-level-switcher' ); ?></p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Roles allowed to switch', 'brm-level-switcher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'User roles allowed to use the switcher', 'brm-level-switcher' ); ?></legend>
								<?php foreach ( $roles as $role_slug => $role ) : ?>
									<label style="display:block;margin:.25em 0;">
										<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[roles][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $settings['roles'], true ) ); ?> />
										<?php echo esc_html( $role['name'] ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description" style="color:#b32d2e;">
								<strong><?php esc_html_e( 'Warning:', 'brm-level-switcher' ); ?></strong>
								<?php esc_html_e( 'Any role ticked here can change its own BricksMembers level from the front-end admin bar — including granting itself paid levels. Enable non-admin roles only on staging/dev sites.', 'brm-level-switcher' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'Administrators can always use the switcher.', 'brm-level-switcher' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param array<int|string,string> $links Existing links.
 * @return array<int|string,string>
 */
function plugin_action_links( array $links ): array {
	$url  = menu_page_url( PAGE_SLUG, false );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'brm-level-switcher' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

/* -------------------------------------------------------------------------
 * Hooks
 * ---------------------------------------------------------------------- */

add_action( 'admin_bar_menu', __NAMESPACE__ . '\\admin_bar_menu', 100 );
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\handle_switch' );
add_action( 'wp_head', __NAMESPACE__ . '\\admin_bar_styles' );
add_action( 'admin_head', __NAMESPACE__ . '\\admin_bar_styles' );
add_action( 'wp_footer', __NAMESPACE__ . '\\strip_cache_bust_arg' );
add_action( 'admin_footer', __NAMESPACE__ . '\\strip_cache_bust_arg' );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_settings_page', 100 );
add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\plugin_action_links' );
