<?php
/**
 * Admin settings page.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Transient key for the one-time plaintext token display.
	 */
	const FLASH_TOKEN = 'wpmcp_flash_token_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Register the menu page.
	 */
	public function menu() {
		add_options_page(
			'WP MCP Control',
			'MCP Control',
			'manage_options',
			'wp-mcp-control',
			array( $this, 'render' )
		);
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_actions() {
		if ( empty( $_POST['wpmcp_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpmcp_settings' );

		$action = sanitize_text_field( wp_unslash( $_POST['wpmcp_action'] ) );
		$s      = wp_parse_args( (array) get_option( Auth::OPTION, array() ), array( 'disabled_tools' => array() ) );

		if ( 'save' === $action ) {
			$s['enabled'] = ! empty( $_POST['enabled'] );
			$user_id      = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			if ( $user_id && ! $this->validate_actor( $user_id ) ) {
				return;
			}
			$s['user_id'] = $user_id;
			// The form posts the tools that are CHECKED (= enabled); we store the
			// complement. Unchecked boxes are simply absent from $_POST, so the
			// list must be derived from the full registry, not from the request.
			$checked = isset( $_POST['enabled_tools'] ) ? (array) wp_unslash( $_POST['enabled_tools'] ) : array();
			$checked = array_map( 'sanitize_text_field', $checked );
			$s['disabled_tools'] = array_values( array_diff( wpmcp()->tools->all_names(), $checked ) );
			update_option( Auth::OPTION, $s );
			add_settings_error( 'wpmcp', 'saved', 'Settings saved.', 'updated' );
		}

		if ( 'rotate' === $action ) {
			$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			if ( ! $user_id ) {
				add_settings_error( 'wpmcp', 'nouser', 'Select a user before generating a token.', 'error' );
			} elseif ( $this->validate_actor( $user_id ) ) {
				$token = wpmcp()->auth->rotate_token( $user_id );
				set_transient( self::FLASH_TOKEN . get_current_user_id(), $token, 120 );
				add_settings_error( 'wpmcp', 'rotated', 'New token generated. Copy it now — it will not be shown again.', 'updated' );
			}
		}
		// Notices are rendered from the global store on this same request (no redirect,
		// so add_settings_error() output survives to render()).
	}

	/**
	 * Validate that the submitted actor user is allowed.
	 *
	 * The dropdown restricts the choices visually (role__in), but the posted
	 * user_id is attacker-controllable. On multisite an unchecked value lets a
	 * single-site admin mint a token acting as the network super-admin.
	 *
	 * @param int $user_id Candidate user ID.
	 * @return bool True when acceptable; otherwise emits a notice and returns false.
	 */
	private function validate_actor( $user_id ) {
		$user    = get_user_by( 'id', $user_id );
		$allowed = array( 'administrator', 'editor', 'shop_manager' );

		if ( ! $user || ! array_intersect( $allowed, (array) $user->roles ) ) {
			add_settings_error( 'wpmcp', 'baduser', 'Selected user is not a permitted role.', 'error' );
			return false;
		}
		if ( is_multisite() ) {
			if ( is_super_admin( $user_id ) && ! is_super_admin( get_current_user_id() ) ) {
				add_settings_error( 'wpmcp', 'baduser', 'You cannot issue a token acting as a network administrator.', 'error' );
				return false;
			}
			if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				add_settings_error( 'wpmcp', 'baduser', 'Selected user is not a member of this site.', 'error' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		$s        = wp_parse_args(
			(array) get_option( Auth::OPTION, array() ),
			array(
				'enabled'        => false,
				'user_id'        => 0,
				'token_hash'     => '',
				'disabled_tools' => array(),
			)
		);
		$endpoint = rest_url( WPMCP_NAMESPACE . '/mcp' );
		// Scoped to the current admin so a second manage_options user loading
		// this page cannot consume a token minted for someone else.
		$flash_key = self::FLASH_TOKEN . get_current_user_id();
		$flash     = get_transient( $flash_key );
		delete_transient( $flash_key );
		$tools    = wpmcp()->tools->all_names();
		sort( $tools );

		settings_errors( 'wpmcp' );
		?>
		<div class="wrap">
			<h1>WP MCP Control</h1>
			<p>Free Model Context Protocol server. Connect Claude, ChatGPT and other MCP clients to control this site.</p>

			<h2>Connection</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">MCP Endpoint URL</th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">Status</th>
					<td>
						<?php if ( wpmcp()->auth->is_enabled() ) : ?>
							<span style="color:#008a20;">&#10004; Active &mdash; ready to accept connections.</span>
						<?php else : ?>
							<span style="color:#b32d2e;">Not configured &mdash; select a user and generate a token below.</span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php
			if ( $flash ) :
				$ready_url = add_query_arg( 'token', rawurlencode( $flash ), $endpoint );
				$config    = wp_json_encode(
					array(
						'mcpServers' => array(
							'wordpress' => array(
								'command' => 'npx',
								'args'    => array( '-y', 'mcp-remote', $endpoint, '--header', 'Authorization: Bearer ' . $flash ),
							),
						),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);
				?>
				<div class="notice notice-warning" style="padding-bottom:8px;">
					<p><strong>New token generated — copy what you need now. It will not be shown again.</strong></p>

					<p style="margin-bottom:4px;"><strong>Token</strong></p>
					<p style="display:flex;gap:6px;align-items:center;">
						<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $flash ); ?>" onclick="this.select()">
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>

					<p style="margin-bottom:4px;"><strong>Ready-to-paste connection URL</strong> — for claude.ai custom connectors and any client that takes a single URL:</p>
					<p style="display:flex;gap:6px;align-items:center;">
						<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $ready_url ); ?>" onclick="this.select()">
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>
					<p class="description" style="margin-top:0;">The token is part of this URL. Treat the whole URL as a password — anyone who has it controls the site as the configured user.</p>

					<p style="margin-bottom:4px;"><strong>Claude Desktop / Claude Code config</strong> — paste into your MCP config file:</p>
					<p style="display:flex;gap:6px;align-items:flex-start;">
						<textarea readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;height:9em;" onclick="this.select()"><?php echo esc_textarea( $config ); ?></textarea>
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>
				</div>
				<script>
				( function () {
					document.querySelectorAll( '.wpmcp-copy' ).forEach( function ( btn ) {
						btn.addEventListener( 'click', function () {
							var src = btn.parentNode.querySelector( '.wpmcp-copy-src' );
							src.select();
							var done = function () { btn.textContent = 'Copied ✓'; setTimeout( function () { btn.textContent = 'Copy'; }, 1500 ); };
							if ( navigator.clipboard ) {
								navigator.clipboard.writeText( src.value ).then( done, function () { document.execCommand( 'copy' ); done(); } );
							} else {
								document.execCommand( 'copy' );
								done();
							}
						} );
					} );
				}() );
				</script>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wpmcp_settings' ); ?>
				<h2>Settings</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable MCP server</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Accept MCP requests</label></td>
					</tr>
					<tr>
						<th scope="row">Act as user</th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'             => 'user_id',
									'selected'         => $s['user_id'],
									'show_option_none' => '— select —',
									'option_none_value'=> 0,
									'role__in'         => array( 'administrator', 'editor', 'shop_manager' ),
								)
							);
							?>
							<p class="description">All MCP actions run as this user; WordPress capabilities are enforced accordingly. Use an administrator for full control.</p>
						</td>
					</tr>
				</table>

				<?php
				$disabled_count = count( array_intersect( $tools, (array) $s['disabled_tools'] ) );
				$active_count   = count( $tools ) - $disabled_count;
				?>
				<h2>Enabled tools</h2>
				<p class="description">
					Uncheck a tool to disable it entirely (it will not appear in tools/list).
					<strong><?php echo (int) $active_count; ?></strong> of <strong><?php echo count( $tools ); ?></strong> tools active.
				</p>
				<p>
					<button type="button" class="button" id="wpmcp-check-all">Select all</button>
					<button type="button" class="button" id="wpmcp-uncheck-all">Deselect all</button>
					<span class="description">Ticks the boxes below &mdash; press &ldquo;Save settings&rdquo; to apply.</span>
				</p>
				<div style="column-count:3;column-gap:2em;max-width:1100px;">
					<?php
					foreach ( $tools as $tool ) :
						$is_on = ! in_array( $tool, (array) $s['disabled_tools'], true );
						?>
						<label style="display:block;">
							<input type="checkbox" class="wpmcp-tool" name="enabled_tools[]" value="<?php echo esc_attr( $tool ); ?>"
								<?php checked( $is_on ); ?>>
							<span style="text-decoration:<?php echo $is_on ? 'none' : 'line-through'; ?>;"><?php echo esc_html( $tool ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description">Checked = <strong>enabled</strong>. Unchecked tools do not appear in <code>tools/list</code>.</p>
				<script>
				( function () {
					var set = function ( on ) {
						document.querySelectorAll( '.wpmcp-tool' ).forEach( function ( cb ) {
							cb.checked = on;
							cb.parentNode.querySelector( 'span' ).style.textDecoration = on ? 'none' : 'line-through';
						} );
					};
					document.getElementById( 'wpmcp-check-all' ).addEventListener( 'click', function () { set( true ); } );
					document.getElementById( 'wpmcp-uncheck-all' ).addEventListener( 'click', function () { set( false ); } );
				}() );
				</script>

				<p class="submit">
					<button type="submit" name="wpmcp_action" value="save" class="button button-primary">Save settings</button>
					<button type="submit" name="wpmcp_action" value="rotate" class="button">Generate / rotate token</button>
				</p>
			</form>

			<h2>Connect a client</h2>

			<h3 style="margin-bottom:4px;">Option A — claude.ai (recommended): sign in with WordPress</h3>
			<p style="margin-top:0;">
				In Claude, add a custom connector and paste this <strong>full MCP endpoint URL</strong>
				(not just the domain — the site root is not an MCP server):
			</p>
			<p style="display:flex;gap:6px;align-items:center;max-width:760px;">
				<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select()">
				<button type="button" class="button wpmcp-copy2" data-copy="<?php echo esc_attr( $endpoint ); ?>">Copy</button>
			</p>
			<p class="description" style="margin-top:0;">
				Claude reads the URL, opens your WordPress login, you approve access once, and it connects —
				no token to copy or paste. Access is granted as the WordPress user who signs in.
				<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
					<br><strong style="color:#b32d2e;">Your site uses plain permalinks.</strong> Set
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings → Permalinks</a>
					to any &ldquo;pretty&rdquo; option so the sign-in endpoints work.
				<?php endif; ?>
			</p>

			<h3 style="margin-bottom:4px;">Option B — token (Claude Desktop, Claude Code, scripts)</h3>
			<p style="margin-top:0;">
				Press <strong>Generate / rotate token</strong> above. The connection URL and a ready-made config
				appear at the top of this page, each with a Copy button.
			</p>

			<script>
			( function () {
				document.querySelectorAll( '.wpmcp-copy2' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var v = btn.getAttribute( 'data-copy' );
						var done = function () { btn.textContent = 'Copied ✓'; setTimeout( function () { btn.textContent = 'Copy'; }, 1500 ); };
						if ( navigator.clipboard ) { navigator.clipboard.writeText( v ).then( done, done ); } else { done(); }
					} );
				} );
			}() );
			</script>
		</div>
		<?php
	}
}
