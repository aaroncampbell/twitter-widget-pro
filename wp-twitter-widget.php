<?php
/**
 * Plugin Name: Twitter Widget Pro
 * Plugin URI: https://aarondcampbell.com/wordpress-plugin/twitter-widget-pro/
 * Description: A widget that properly handles twitter feeds, including @username, #hashtag, and link parsing.  It can even display profile images for the users.  Requires PHP5.
 * Version: 2.9.0
 * Author: Aaron D. Campbell
 * Author URI: https://aarondcampbell.com/
 * License: GPLv2 or later
 * Text Domain: twitter-widget-pro
 */

/*
	Copyright 2006-current  Aaron D. Campbell  ( email : wp_plugins@xavisys.com )

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	( at your option ) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require_once( 'tlc-transients.php' );
require_once( 'class.wp_widget_twitter_pro.php' );

/**
 * wpTwitterWidget is the class that handles everything outside the widget. This
 * includes filters that modify tweet content for things like linked usernames.
 * It also helps us avoid name collisions.
 */
class wpTwitterWidget {
	/**
	 * @var wpTwitter
	 */
	private $_wp_twitter_oauth;

	/**
	 * @var wpTwitterWidget - Static property to hold our singleton instance
	 */
	static $instance = false;

	/**
	 * @var array Plugin settings
	 */
	protected $_settings;

	/**
	 * @var string - The options page name used in the URL
	 */
	protected $_hook = 'twitterWidgetPro';

	/**
	 * @var string - The filename for the main plugin file
	 */
	protected $_file = '';

	/**
	 * @var string - The options page title
	 */
	protected $_pageTitle = '';

	/**
	 * @var string - The options page menu title
	 */
	protected $_menuTitle = '';

	/**
	 * @var string - The access level required to see the options page
	 */
	protected $_accessLevel = 'manage_options';

	/**
	 * @var string - The option group to register
	 */
	protected $_optionGroup = 'twp-options';

	/**
	 * @var array - An array of options to register to the option group
	 */
	protected $_optionNames = array( 'twp' );

	/**
	 * @var array - An associated array of callbacks for the options, option name should be index, callback should be value
	 */
	protected $_optionCallbacks = array();

	/**
	 * @var string - The plugin slug used on WordPress.org
	 */
	protected $_slug = '';

	/**
	 * @var string - The feed URL for AaronDCampbell.com
	 */
	protected $_feed_url = 'http://aarondcampbell.com/feed/';

	/**
	 * @var string - The button ID for the PayPal button, override this generic one with a plugin-specific one
	 */
	protected $_paypalButtonId = '9993090';

	protected $_optionsPageAction = 'options.php';

	/**
	 * This is our constructor, which is private to force the use of getInstance()
	 * @return void
	 */
	protected function __construct() {
		$this->_file = plugin_basename( __FILE__ );
		$this->_pageTitle = __( 'Twitter Widget Pro', 'twitter-widget-pro' );
		$this->_menuTitle = __( 'Twitter Widget', 'twitter-widget-pro' );

		/**
		 * Add filters and actions
		 */
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'show_messages' ) );
		add_action( 'widgets_init', array( $this, 'register' ), 11 );
		add_filter( 'widget_twitter_content', array( $this, 'linkTwitterUsers' ) );
		add_filter( 'widget_twitter_content', array( $this, 'linkUrls' ) );
		add_filter( 'widget_twitter_content', array( $this, 'linkHashtags' ) );
		add_filter( 'widget_twitter_content', 'convert_chars' );
		add_filter( 'twitter-widget-pro-opt-twp', array( $this, 'filterSettings' ) );
		add_filter( 'twitter-widget-pro-opt-twp-authed-users', array( $this, 'authed_users_option' ) );
		add_shortcode( 'twitter-widget', array( $this, 'handleShortcodes' ) );

		$this->_get_settings();
		if ( is_callable( array($this, '_post_settings_init') ) )
			$this->_post_settings_init();

		add_filter( 'init', array( $this, 'init_locale' ) );
		add_action( 'admin_init', array( $this, 'register_options' ) );
		add_filter( 'plugin_action_links', array( $this, 'add_plugin_page_links' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_meta_links' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_options_page' ) );
		if ( is_callable(array( $this, 'add_options_meta_boxes' )) )
			add_action( 'admin_init', array( $this, 'add_options_meta_boxes' ) );

		add_action( 'admin_init', array( $this, 'add_default_options_meta_boxes' ) );
		add_action( 'admin_print_scripts', array( $this,'admin_print_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this,'admin_enqueue_scripts' ) );

		add_action ( 'in_plugin_update_message-'.$this->_file , array ( $this , 'changelog' ), null, 2 );
	}

	protected function _post_settings_init() {
		$oauth_settings = array(
			'consumer-key'    => $this->_settings['twp']['consumer-key'],
			'consumer-secret' => $this->_settings['twp']['consumer-secret'],
		);
		if ( ! class_exists( 'wpTwitter' ) ) {
			require_once( 'lib/wp-twitter.php' );
		}
		$this->_wp_twitter_oauth = new wpTwitter( $oauth_settings );

		// We want to fill 'twp-authed-users' but not overwrite them when saving
		$this->_settings['twp-authed-users'] = apply_filters('twitter-widget-pro-opt-twp-authed-users', get_option('twp-authed-users'));
	}

	/**
	 * Function to instantiate our class and make it a singleton
	 */
	public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;

		return self::$instance;
	}

	public function handle_actions() {
		if ( empty( $_GET['action'] ) || empty( $_GET['page'] ) || $_GET['page'] != $this->_hook )
			return;

		if ( 'clear-locks' == $_GET['action'] ) {
			check_admin_referer( 'clear-locks' );
			$redirect_args = array( 'message' => strtolower( $_GET['action'] ) );
			global $wpdb;
			$locks_q = "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_tlc_up__twp%'";
			$redirect_args['locks_cleared'] = $wpdb->query( $locks_q );
			wp_safe_redirect( add_query_arg( $redirect_args, remove_query_arg( array( 'action', '_wpnonce' ) ) ) );
			exit;
		}

		if ( 'remove' == $_GET['action'] ) {
			check_admin_referer( 'remove-' . $_GET['screen_name'] );

			$redirect_args = array(
				'message'    => 'removed',
				'removed' => '',
			);
			unset( $this->_settings['twp-authed-users'][strtolower($_GET['screen_name'])] );
			if ( update_option( 'twp-authed-users', $this->_settings['twp-authed-users'] ) );
				$redirect_args['removed'] = $_GET['screen_name'];

			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_options_url() ) );
			exit;
		}
		if ( 'authorize' == $_GET['action'] ) {
			check_admin_referer( 'authorize' );
			$auth_redirect = add_query_arg( array( 'action' => 'authorized' ), $this->get_options_url() );
			$token = $this->_wp_twitter_oauth->get_request_token( $auth_redirect );
			if ( is_wp_error( $token ) ) {
				$this->_error = $token;
				return;
			}
			update_option( '_twp_request_token_'.$token['nonce'], $token );
			$screen_name = empty( $_GET['screen_name'] )? '':$_GET['screen_name'];
			wp_redirect( $this->_wp_twitter_oauth->get_authorize_url( $screen_name ) );
			exit;
		}
		if ( 'authorized' == $_GET['action'] ) {
			$redirect_args = array(
				'message'    => strtolower( $_GET['action'] ),
				'authorized' => '',
			);
			if ( empty( $_GET['oauth_verifier'] ) || empty( $_GET['nonce'] ) )
				wp_safe_redirect( add_query_arg( $redirect_args, $this->get_options_url() ) );

			$this->_wp_twitter_oauth->set_token( get_option( '_twp_request_token_'.$_GET['nonce'] ) );
			delete_option( '_twp_request_token_'.$_GET['nonce'] );

			$token = $this->_wp_twitter_oauth->get_access_token( $_GET['oauth_verifier'] );
			if ( ! is_wp_error( $token ) ) {
				$this->_settings['twp-authed-users'][strtolower($token['screen_name'])] = $token;
				update_option( 'twp-authed-users', $this->_settings['twp-authed-users'] );

				$redirect_args['authorized'] = $token['screen_name'];
			}
			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_options_url() ) );
			exit;
		}
	}

	public function show_messages() {
		if ( ! empty( $_GET['message'] ) ) {
			if ( 'clear-locks' == $_GET['message'] ) {
				if ( empty( $_GET['locks_cleared'] ) || 0 == $_GET['locks_cleared'] )
					$msg = __( 'There were no locks to clear!', 'twitter-widget-pro' );
				else
					$msg = sprintf( _n( 'Successfully cleared %d lock.', 'Successfully cleared %d locks.', $_GET['locks_cleared'], 'twitter-widget-pro' ), $_GET['locks_cleared'] );
			} elseif ( 'authorized' == $_GET['message'] ) {
				if ( ! empty( $_GET['authorized'] ) )
					$msg = sprintf( __( 'Successfully authorized @%s', 'twitter-widget-pro' ), $_GET['authorized'] );
				else
					$msg = __( 'There was a problem authorizing your account.', 'twitter-widget-pro' );
			} elseif ( 'removed' == $_GET['message'] ) {
				if ( ! empty( $_GET['removed'] ) )
					$msg = sprintf( __( 'Successfully removed @%s', 'twitter-widget-pro' ), $_GET['removed'] );
				else
					$msg = __( 'There was a problem removing your account.', 'twitter-widget-pro' );
			}
			if ( ! empty( $msg ) )
				echo "<div class='updated'><p>" . esc_html( $msg ) . '</p></div>';
		}

		if ( ! empty( $this->_error ) && is_wp_error( $this->_error ) ) {
			$msg = '<p>' . implode( '</p><p>', $this->_error->get_error_messages() ) . '</p>';
			echo '<div class="error">' . $msg . '</div>';
		}

		if ( empty( $this->_settings['twp']['consumer-key'] ) || empty( $this->_settings['twp']['consumer-secret'] ) ) {
			$msg = sprintf( __( 'You need to <a href="%s">set up your Twitter app keys</a>.', 'twitter-widget-pro' ), $this->get_options_url() );
			echo '<div class="error"><p>' . $msg . '</p></div>';
		}

		if ( empty( $this->_settings['twp-authed-users'] ) ) {
			$msg = sprintf( __( 'You need to <a href="%s">authorize your Twitter accounts</a>.', 'twitter-widget-pro' ), $this->get_options_url() );
			echo '<div class="error"><p>' . $msg . '</p></div>';
		}
	}

	public function add_options_meta_boxes() {
		add_meta_box( 'twitter-widget-pro-oauth', __( 'Authenticated Twitter Accounts', 'twitter-widget-pro' ), array( $this, 'oauth_meta_box' ), 'aaron-twitter-widget-pro', 'main' );
		add_meta_box( 'twitter-widget-pro-general-settings', __( 'General Settings', 'twitter-widget-pro' ), array( $this, 'general_settings_meta_box' ), 'aaron-twitter-widget-pro', 'main' );
		add_meta_box( 'twitter-widget-pro-defaults', __( 'Default Settings for Shortcodes', 'twitter-widget-pro' ), array( $this, 'default_settings_meta_box' ), 'aaron-twitter-widget-pro', 'main' );
	}

	public function oauth_meta_box() {
		$authorize_url = wp_nonce_url( add_query_arg( array( 'action' => 'authorize' ) ), 'authorize' );

		?>
		<table class="widefat">
			<thead>
				<tr valign="top">
					<th scope="row">
						<?php _e( 'Username', 'twitter-widget-pro' );?>
					</th>
					<th scope="row">
						<?php _e( 'Lists Rate Usage', 'twitter-widget-pro' );?>
					</th>
					<th scope="row">
						<?php _e( 'Statuses Rate Usage', 'twitter-widget-pro' );?>
					</th>
				</tr>
			</thead>
		<?php
		foreach ( $this->_settings['twp-authed-users'] as $u ) {
			$this->_wp_twitter_oauth->set_token( $u );
			$rates = $this->_wp_twitter_oauth->send_authed_request( 'application/rate_limit_status', 'GET', array( 'resources' => 'statuses,lists' ) );
			$style = $auth_link = '';
			if ( is_wp_error( $rates ) ) {
				$query_args = array(
					'action' => 'authorize',
					'screen_name' => $u['screen_name'],
				);
				$authorize_user_url = wp_nonce_url( add_query_arg( $query_args ), 'authorize' );
				$style = 'color:red;';
				$auth_link = ' - <a href="' . esc_url( $authorize_user_url ) . '">' . __( 'Reauthorize', 'twitter-widget-pro' ) . '</a>';
			}
			$query_args = array(
				'action' => 'remove',
				'screen_name' => $u['screen_name'],
			);
			$remove_user_url = wp_nonce_url( add_query_arg( $query_args ), 'remove-' . $u['screen_name'] );
			?>
				<tr valign="top">
					<th scope="row" style="<?php echo esc_attr( $style ); ?>">
						<strong>@<?php echo esc_html( $u['screen_name'] ) . $auth_link;?></strong>
						<br /><a href="<?php echo esc_url( $remove_user_url ) ?>"><?php _e( 'Remove', 'twitter-widget-pro' ) ?></a>
					</th>
					<?php
					if ( ! is_wp_error( $rates ) ) {
						$display_rates = array(
							__( 'Lists', 'twitter-widget-pro' ) => $rates->resources->lists->{'/lists/statuses'},
							__( 'Statuses', 'twitter-widget-pro' ) => $rates->resources->statuses->{'/statuses/user_timeline'},
						);
						foreach ( $display_rates as $title => $rate ) {
						?>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong>
							<p>
								<?php echo sprintf( __( 'Used: %d', 'twitter-widget-pro' ), $rate->limit - $rate->remaining ); ?><br />
								<?php echo sprintf( __( 'Remaining: %d', 'twitter-widget-pro' ), $rate->remaining ); ?><br />
								<?php
								$minutes = ceil( ( $rate->reset - gmdate( 'U' ) ) / 60 );
								echo sprintf( _n( 'Limits reset in: %d minutes', 'Limits reset in: %d minutes', $minutes, 'twitter-widget-pro' ), $minutes );
								?><br />
								<small><?php _e( 'This is overall usage, not just usage from Twitter Widget Pro', 'twitter-widget-pro' ); ?></small>
							</p>
						</td>
						<?php
						}
					} else {
						?>
						<td>
							<p><?php _e( 'There was an error checking your rate limit.', 'twitter-widget-pro' ); ?></p>
						</td>
						<td>
							<p><?php _e( 'There was an error checking your rate limit.', 'twitter-widget-pro' ); ?></p>
						</td>
						<?php
					}
					?>
				</tr>
				<?php
			}
		?>
		</table>
		<?php
		if ( empty( $this->_settings['twp']['consumer-key'] ) || empty( $this->_settings['twp']['consumer-secret'] ) ) {
		?>
		<p>
			<strong><?php _e( 'You need to fill in the Consumer key and Consumer secret before you can authorize accounts.', 'twitter-widget-pro' ) ?></strong>
		</p>
		<?php
		} else {
		?>
		<p>
			<a href="<?php echo esc_url( $authorize_url );?>" class="button button-large button-primary"><?php _e( 'Authorize New Account', 'twitter-widget-pro' ); ?></a>
		</p>
		<?php
		}
	}
	public function general_settings_meta_box() {
		$clear_locks_url = wp_nonce_url( add_query_arg( array( 'action' => 'clear-locks' ) ), 'clear-locks' );
		?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="twp_consumer_key"><?php _e( 'Consumer key', 'twitter-widget-pro' );?></label>
						</th>
						<td>
							<input id="twp_consumer_key" name="twp[consumer-key]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['twp']['consumer-key'] ); ?>" size="40" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_consumer_secret"><?php _e( 'Consumer secret', 'twitter-widget-pro' );?></label>
						</th>
						<td>
							<input id="twp_consumer_secret" name="twp[consumer-secret]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['twp']['consumer-secret'] ); ?>" size="40" />
						</td>
					</tr>
					<?php
					if ( empty( $this->_settings['twp']['consumer-key'] ) || empty( $this->_settings['twp']['consumer-secret'] ) ) {
					?>
					<tr valign="top">
						<th scope="row">&nbsp;</th>
						<td>
							<strong><?php _e( 'Directions to get the Consumer Key and Consumer Secret', 'twitter-widget-pro' ) ?></strong>
							<ol>
								<li><a href="https://dev.twitter.com/apps/new"><?php _e( 'Add a new Twitter application', 'twitter-widget-pro' ) ?></a></li>
								<li><?php _e( "Fill in Name, Description, Website, and Callback URL (don't leave any blank) with anything you want" ) ?></a></li>
								<li><?php _e( "Agree to rules, fill out captcha, and submit your application" ) ?></a></li>
								<li><?php _e( "Copy the Consumer key and Consumer secret into the fields above" ) ?></a></li>
								<li><?php _e( "Click the Update Options button at the bottom of this page" ) ?></a></li>
							</ol>
						</td>
					</tr>
					<?php
					}
					?>
					<tr>
						<th scope="row">
							<?php _e( "Clear Update Locks", 'twitter-widget-pro' );?>
						</th>
						<td>
							<a href="<?php echo esc_url( $clear_locks_url ); ?>"><?php _e( 'Clear Update Locks', 'twitter-widget-pro' ); ?></a><br />
							<small><?php _e( "A small percentage of servers seem to have issues where an update lock isn't getting cleared.  If you're experiencing issues with your feed not updating, try clearing the update locks.", 'twitter-widget-pro' ); ?></small>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php _e( 'Local requests:', 'twitter-widget-pro' ); ?>
						</th>
						<td>
						<?php
							if ( ! empty( $_GET['action'] ) && 'test-local-request' == $_GET['action'] ) {
								check_admin_referer( 'test-local-request' );

								$server_url = home_url( '/?twp-test-local-request' );
								$resp = wp_remote_post( $server_url, array( 'body' => array( '_twp-test-local-request' => 'test' ), 'sslverify' => apply_filters( 'https_local_ssl_verify', true ) ) );
								if ( !is_wp_error( $resp ) && $resp['response']['code'] >= 200 && $resp['response']['code'] < 300 ) {
									if ( 'success' === wp_remote_retrieve_body( $resp ) )
										_e( '<p style="color:green;">Local requests appear to be functioning normally.</p>', 'twitter-widget-pro' );
									else
										_e( '<p style="color:red;">The request went through, but an unexpected response was received.</p>', 'twitter-widget-pro' );
								} else {
									printf( __( '<p style="color:red;">Failed.  Your server said: %s</p>', 'twitter-widget-pro' ), $resp['response']['message'] );
								}
							}
							$query_args = array(
								'action' => 'test-local-request',
							);
							$test_local_url = wp_nonce_url( add_query_arg( $query_args, $this->get_options_url() ), 'test-local-request' );
							?>
							<a href="<?php echo esc_url( $test_local_url ); ?>" class="button">
								<?php _e( 'Test local requests', 'twitter-widget-pro' ); ?>
							</a><br />
							<small><?php _e( "Twitter Widget Pro updates tweets in the background by placing a local request to your server.  If your Tweets aren't updating, test this.  If it fails, let your host know that loopback requests aren't working on your site.", 'twitter-widget-pro' ); ?></small>
						</td>
					</tr>
				</table>
		<?php
	}
	public function default_settings_meta_box() {
		$users = $this->get_users_list( true );
		$lists = $this->get_lists();
		?>
				<p><?php _e( 'These settings are the default for the shortcodes and all of them can be overridden by specifying a different value in the shortcode itself.  All settings for widgets are locate in the individual widget.', 'twitter-widget-pro' ) ?></p>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="twp_username"><?php _e( 'Twitter username:', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<select id="twp_username" name="twp[username]">
								<option></option>
								<?php
								$selected = false;
								foreach ( $users as $u ) {
									?>
									<option value="<?php echo esc_attr( strtolower( $u['screen_name'] ) ); ?>"<?php $s = selected( strtolower( $u['screen_name'] ), strtolower( $this->_settings['twp']['username'] ) ) ?>><?php echo esc_html( $u['screen_name'] ); ?></option>
									<?php
									if ( ! empty( $s ) )
										$selected = true;
								}
								?>
							</select>
							<?php
							if ( ! $selected && ! empty( $this->_settings['twp']['username'] ) ) {
								$query_args = array(
									'action' => 'authorize',
									'screen_name' => $this->_settings['twp']['username'],
								);
								$authorize_user_url = wp_nonce_url( add_query_arg( $query_args, $this->get_options_url() ), 'authorize' );
								?>
							<p>
								<a href="<?php echo esc_url( $authorize_user_url ); ?>" style="color:red;">
									<?php _e( 'You need to authorize this account.', 'twitter-widget-pro' ); ?>
								</a>
							</p>
								<?php
							}
							?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_list"><?php _e( 'Twitter list:', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<select id="twp_list" name="twp[list]">
								<option></option>
								<?php
								foreach ( $lists as $user => $user_lists ) {
									echo '<optgroup label="' . esc_attr( $user ) . '">';
									foreach ( $user_lists as $list_id => $list_name ) {
										?>
										<option value="<?php echo esc_attr( $user . '::' . $list_id ); ?>"<?php $s = selected( $user . '::' . $list_id, strtolower( $this->_settings['twp']['list'] ) ) ?>><?php echo esc_html( $list_name ); ?></option>
										<?php
									}
									echo '</optgroup>';
								}
								?>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_title"><?php _e( 'Give the feed a title ( optional ):', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<input id="twp_title" name="twp[title]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['twp']['title'] ); ?>" size="40" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_items"><?php _e( 'How many items would you like to display?', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<select id="twp_items" name="twp[items]">
								<?php
									for ( $i = 1; $i <= 20; ++$i ) {
										echo "<option value='$i' ". selected( $this->_settings['twp']['items'], $i, false ). ">$i</option>";
									}
								?>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_avatar"><?php _e( 'Display profile image?', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<select id="twp_avatar" name="twp[avatar]">
								<option value=""<?php selected( $this->_settings['twp']['avatar'], '' ) ?>><?php _e( 'Do not show', 'twitter-widget-pro' ); ?></option>
								<option value="mini"<?php selected( $this->_settings['twp']['avatar'], 'mini' ) ?>><?php _e( 'Mini - 24px by 24px', 'twitter-widget-pro' ); ?></option>
								<option value="normal"<?php selected( $this->_settings['twp']['avatar'], 'normal' ) ?>><?php _e( 'Normal - 48px by 48px', 'twitter-widget-pro' ); ?></option>
								<option value="bigger"<?php selected( $this->_settings['twp']['avatar'], 'bigger' ) ?>><?php _e( 'Bigger - 73px by 73px', 'twitter-widget-pro' ); ?></option>
								<option value="original"<?php selected( $this->_settings['twp']['avatar'], 'original' ) ?>><?php _e( 'Original', 'twitter-widget-pro' ); ?></option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_errmsg"><?php _e( 'What to display when Twitter is down ( optional ):', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<input id="twp_errmsg" name="twp[errmsg]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['twp']['errmsg'] ); ?>" size="40" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_showts"><?php _e( 'Show date/time of Tweet ( rather than 2 ____ ago ):', 'twitter-widget-pro' ); ?></label>
						</th>
						<td>
							<select id="twp_showts" name="twp[showts]">
								<option value="0" <?php selected( $this->_settings['twp']['showts'], '0' ); ?>><?php _e( 'Always', 'twitter-widget-pro' );?></option>
								<option value="3600" <?php selected( $this->_settings['twp']['showts'], '3600' ); ?>><?php _e( 'If over an hour old', 'twitter-widget-pro' );?></option>
								<option value="86400" <?php selected( $this->_settings['twp']['showts'], '86400' ); ?>><?php _e( 'If over a day old', 'twitter-widget-pro' );?></option>
								<option value="604800" <?php selected( $this->_settings['twp']['showts'], '604800' ); ?>><?php _e( 'If over a week old', 'twitter-widget-pro' );?></option>
								<option value="2592000" <?php selected( $this->_settings['twp']['showts'], '2592000' ); ?>><?php _e( 'If over a month old', 'twitter-widget-pro' );?></option>
								<option value="31536000" <?php selected( $this->_settings['twp']['showts'], '31536000' ); ?>><?php _e( 'If over a year old', 'twitter-widget-pro' );?></option>
								<option value="-1" <?php selected( $this->_settings['twp']['showts'], '-1' ); ?>><?php _e( 'Never', 'twitter-widget-pro' );?></option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="twp_dateFormat"><?php echo sprintf( __( 'Format to display the date in, uses <a href="%s">PHP date()</a> format:', 'twitter-widget-pro' ), 'http://php.net/date' ); ?></label>
						</th>
						<td>
							<input id="twp_dateFormat" name="twp[dateFormat]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['twp']['dateFormat'] ); ?>" size="40" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<?php _e( "Other Setting:", 'twitter-widget-pro' );?>
						</th>
						<td>
							<input type="hidden" value="false" name="twp[showretweets]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_showretweets" name="twp[showretweets]"<?php checked( $this->_settings['twp']['showretweets'], 'true' ); ?> />
							<label for="twp_showretweets"><?php _e( 'Include retweets', 'twitter-widget-pro' ); ?></label>
							<br />
							<input type="hidden" value="false" name="twp[hidereplies]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_hidereplies" name="twp[hidereplies]"<?php checked( $this->_settings['twp']['hidereplies'], 'true' ); ?> />
							<label for="twp_hidereplies"><?php _e( 'Hide @replies', 'twitter-widget-pro' ); ?></label>
							<br />
							<input type="hidden" value="false" name="twp[hidefrom]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_hidefrom" name="twp[hidefrom]"<?php checked( $this->_settings['twp']['hidefrom'], 'true' ); ?> />
							<label for="twp_hidefrom"><?php _e( 'Hide sending applications', 'twitter-widget-pro' ); ?></label>
							<br />
							<input type="hidden" value="false" name="twp[showintents]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_showintents" name="twp[showintents]"<?php checked( $this->_settings['twp']['showintents'], 'true' ); ?> />
							<label for="twp_showintents"><?php _e( 'Show Tweet Intents (reply, retweet, favorite)', 'twitter-widget-pro' ); ?></label>
							<br />
							<input type="hidden" value="false" name="twp[showfollow]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_showfollow" name="twp[showfollow]"<?php checked( $this->_settings['twp']['showfollow'], 'true' ); ?> />
							<label for="twp_showfollow"><?php _e( 'Show Follow Link', 'twitter-widget-pro' ); ?></label>
							<br />
							<input type="hidden" value="false" name="twp[targetBlank]" />
							<input class="checkbox" type="checkbox" value="true" id="twp_targetBlank" name="twp[targetBlank]"<?php checked( $this->_settings['twp']['targetBlank'], 'true' ); ?> />
							<label for="twp_targetBlank"><?php _e( 'Open links in a new window', 'twitter-widget-pro' ); ?></label>
						</td>
					</tr>
				</table>
		<?php
	}

	/**
	 * Replace @username with a link to that twitter user
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with @replies linked
	 */
	public function linkTwitterUsers( $text ) {
		$text = preg_replace_callback('/(^|\s)@(\w+)/i', array($this, '_linkTwitterUsersCallback'), $text);
		return $text;
	}

	private function _linkTwitterUsersCallback( $matches ) {
		$linkAttrs = array(
			'href'	=> 'http://twitter.com/' . urlencode( $matches[2] ),
			'class'	=> 'twitter-user'
		);
		return $matches[1] . $this->_buildLink( '@'.$matches[2], $linkAttrs );
	}

	/**
	 * Replace #hashtag with a link to twitter.com for that hashtag
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with #hashtags linked
	 */
	public function linkHashtags( $text ) {
		$text = preg_replace_callback('/(^|\s)(#[\w\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{00FF}]+)/iu', array($this, '_linkHashtagsCallback'), $text);
		return $text;
	}

	/**
	 * Replace #hashtag with a link to twitter.com for that hashtag
	 *
	 * @param array $matches - Tweet text
	 * @return string - Tweet text with #hashtags linked
	 */
	private function _linkHashtagsCallback( $matches ) {
		$linkAttrs = array(
			'href'	=> 'http://twitter.com/search?q=' . urlencode( $matches[2] ),
			'class'	=> 'twitter-hashtag'
		);
		return $matches[1] . $this->_buildLink( $matches[2], $linkAttrs );
	}

	/**
	 * Turn URLs into links
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with URLs repalced with links
	 */
	public function linkUrls( $text ) {
		$text = " {$text} "; // Pad with whitespace to simplify the regexes

		$url_clickable = '~
			([\\s(<.,;:!?])                                        # 1: Leading whitespace, or punctuation
			(                                                      # 2: URL
				[\\w]{1,20}+://                                # Scheme and hier-part prefix
				(?=\S{1,2000}\s)                               # Limit to URLs less than about 2000 characters long
				[\\w\\x80-\\xff#%\\~/@\\[\\]*(+=&$-]*+         # Non-punctuation URL character
				(?:                                            # Unroll the Loop: Only allow puctuation URL character if followed by a non-punctuation URL character
					[\'.,;:!?)]                            # Punctuation URL character
					[\\w\\x80-\\xff#%\\~/@\\[\\]*(+=&$-]++ # Non-punctuation URL character
				)*
			)
			(\)?)                                                  # 3: Trailing closing parenthesis (for parethesis balancing post processing)
		~xS';
		// The regex is a non-anchored pattern and does not have a single fixed starting character.
		// Tell PCRE to spend more time optimizing since, when used on a page load, it will probably be used several times.

		$text = preg_replace_callback( $url_clickable, array($this, '_make_url_clickable_cb'), $text );

		$text = preg_replace_callback( '#([\s>])((www|ftp)\.[\w\\x80-\\xff\#$%&~/.\-;:=,?@\[\]+]+)#is', array($this, '_make_web_ftp_clickable_cb' ), $text );
		$text = preg_replace_callback( '#([\s>])([.0-9a-z_+-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})#i', array($this, '_make_email_clickable_cb' ), $text );

		$text = substr( $text, 1, -1 ); // Remove our whitespace padding.

		return $text;
	}

	function _make_web_ftp_clickable_cb($matches) {
		$ret = '';
		$dest = $matches[2];
		$dest = 'http://' . $dest;
		$dest = esc_url($dest);
		if ( empty($dest) )
			return $matches[0];

		// removed trailing [.,;:)] from URL
		if ( in_array( substr($dest, -1), array('.', ',', ';', ':', ')') ) === true ) {
			$ret = substr($dest, -1);
			$dest = substr($dest, 0, strlen($dest)-1);
		}
		$linkAttrs = array(
			'href'	=> $dest
		);
		return $matches[1] . $this->_buildLink( $dest, $linkAttrs ) . $ret;
	}

	private function _make_email_clickable_cb( $matches ) {
		$email = $matches[2] . '@' . $matches[3];
		$linkAttrs = array(
			'href'	=> 'mailto:' . $email
		);
		return $matches[1] . $this->_buildLink( $email, $linkAttrs );
	}

	private function _make_url_clickable_cb ( $matches ) {
		$linkAttrs = array(
			'href'	=> $matches[2]
		);
		return $matches[1] . $this->_buildLink( $matches[2], $linkAttrs );
	}

	private function _notEmpty( $v ) {
		return !( empty( $v ) );
	}

	private function _buildLink( $text, $attributes = array(), $noFilter = false ) {
		$attributes = array_filter( wp_parse_args( $attributes ), array( $this, '_notEmpty' ) );
		$attributes = apply_filters( 'widget_twitter_link_attributes', $attributes );
		$attributes = wp_parse_args( $attributes );

		$text = apply_filters( 'widget_twitter_link_text', $text );
		$noFilter = apply_filters( 'widget_twitter_link_nofilter', $noFilter );
		$link = '<a';
		foreach ( $attributes as $name => $value ) {
			$link .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		$link .= '>';
		if ( $noFilter )
			$link .= $text;
		else
			$link .= esc_html( $text );

		$link .= '</a>';
		return $link;
	}

	public function register() {
		// Fix conflict with Jetpack by disabling their Twitter widget
		unregister_widget( 'Wickett_Twitter_Widget' );
		register_widget( 'WP_Widget_Twitter_Pro' );
	}

	public function targetBlank( $attributes ) {
		$attributes['target'] = '_blank';
		return $attributes;
	}

	public function display( $args ) {
		$args = wp_parse_args( $args );

		if ( 'true' == $args['targetBlank'] )
			add_filter( 'widget_twitter_link_attributes', array( $this, 'targetBlank' ) );

		// Validate our options
		$args['items'] = (int) $args['items'];
		if ( $args['items'] < 1 || 20 < $args['items'] )
			$args['items'] = 10;

		if ( !isset( $args['showts'] ) )
			$args['showts'] = 86400;

		$tweets = $this->_getTweets( $args );
		if ( false === $tweets )
			return '';

		$widgetContent = $args['before_widget'] . '<div>';

		if ( empty( $args['title'] ) )
			$args['title'] = sprintf( __( 'Twitter: %s', 'twitter-widget-pro' ), $args['username'] );

		$args['title'] = apply_filters( 'twitter-widget-title', $args['title'], $args );
		$args['title'] = "<span class='twitterwidget twitterwidget-title'>{$args['title']}</span>";
		$widgetContent .= $args['before_title'] . $args['title'] . $args['after_title'];
		if ( !empty( $tweets[0] ) && is_object( $tweets[0] ) && !empty( $args['avatar'] ) ) {
			$widgetContent .= '<div class="twitter-avatar">';
			$widgetContent .= $this->_getProfileImage( $tweets[0]->user, $args );
			$widgetContent .= '</div>';
		}
		$widgetContent .= '<ul>';
		if ( ! is_array( $tweets ) || count( $tweets ) == 0 ) {
			$widgetContent .= '<li class="wpTwitterWidgetEmpty">' . __( 'No Tweets Available', 'twitter-widget-pro' ) . '</li>';
		} else {
			$count = 0;
			foreach ( $tweets as $tweet ) {
				// Set our "ago" string which converts the date to "# ___(s) ago"
				$tweet->ago = $this->_timeSince( strtotime( $tweet->created_at ), $args['showts'], $args['dateFormat'] );
				$entryContent = apply_filters( 'widget_twitter_content', $tweet->text, $tweet );
				$widgetContent .= '<li>';
				$widgetContent .= "<span class='entry-content'>{$entryContent}</span>";
				$widgetContent .= " <span class='entry-meta'>";
				$widgetContent .= "<span class='time-meta'>";
				$linkAttrs = array(
					'href'	=> "http://twitter.com/{$tweet->user->screen_name}/statuses/{$tweet->id_str}"
				);
				$widgetContent .= $this->_buildLink( $tweet->ago, $linkAttrs );
				$widgetContent .= '</span>';

				if ( 'true' != $args['hidefrom'] ) {
					$from = sprintf( __( 'from %s', 'twitter-widget-pro' ), str_replace( '&', '&amp;', $tweet->source ) );
					$widgetContent .= " <span class='from-meta'>{$from}</span>";
				}
				if ( !empty( $tweet->in_reply_to_screen_name ) ) {
					$rtLinkText = sprintf( __( 'in reply to %s', 'twitter-widget-pro' ), $tweet->in_reply_to_screen_name );
					$widgetContent .=  ' <span class="in-reply-to-meta">';
					$linkAttrs = array(
						'href'	=> "http://twitter.com/{$tweet->in_reply_to_screen_name}/statuses/{$tweet->in_reply_to_status_id_str}",
						'class'	=> 'reply-to'
					);
					$widgetContent .= $this->_buildLink( $rtLinkText, $linkAttrs );
					$widgetContent .= '</span>';
				}
 				$widgetContent .= '</span>';

				if ( 'true' == $args['showintents'] ) {
					$widgetContent .= ' <span class="intent-meta">';
					$lang = $this->_getTwitterLang();
					if ( !empty( $lang ) )
						$linkAttrs['data-lang'] = $lang;

					$linkText = __( 'Reply', 'twitter-widget-pro' );
					$linkAttrs['href'] = "http://twitter.com/intent/tweet?in_reply_to={$tweet->id_str}";
					$linkAttrs['class'] = 'in-reply-to';
					$linkAttrs['title'] = $linkText;
					$widgetContent .= $this->_buildLink( $linkText, $linkAttrs );

					$linkText = __( 'Retweet', 'twitter-widget-pro' );
					$linkAttrs['href'] = "http://twitter.com/intent/retweet?tweet_id={$tweet->id_str}";
					$linkAttrs['class'] = 'retweet';
					$linkAttrs['title'] = $linkText;
					$widgetContent .= $this->_buildLink( $linkText, $linkAttrs );

					$linkText = __( 'Favorite', 'twitter-widget-pro' );
					$linkAttrs['href'] = "http://twitter.com/intent/favorite?tweet_id={$tweet->id_str}";
					$linkAttrs['class'] = 'favorite';
					$linkAttrs['title'] = $linkText;
					$widgetContent .= $this->_buildLink( $linkText, $linkAttrs );
					$widgetContent .= '</span>';
				}
				$widgetContent .= '</li>';

				if ( ++$count >= $args['items'] )
					break;
			}
		}

		$widgetContent .= '</ul>';
		if ( 'true' == $args['showfollow'] && ! empty( $args['username'] ) ) {
			$widgetContent .= '<div class="follow-button">';
			$linkText = "@{$args['username']}";
			$linkAttrs = array(
				'href'	=> "http://twitter.com/{$args['username']}",
				'class'	=> 'twitter-follow-button',
				'title'	=> sprintf( __( 'Follow %s', 'twitter-widget-pro' ), "@{$args['username']}" ),
			);
			$lang = $this->_getTwitterLang();
			if ( !empty( $lang ) )
				$linkAttrs['data-lang'] = $lang;

			$widgetContent .= $this->_buildLink( $linkText, $linkAttrs );
			$widgetContent .= '</div>';
		}

		$widgetContent .= '</div>' . $args['after_widget'];

		if ( 'true' == $args['showintents'] || 'true' == $args['showfollow'] ) {
			$script = 'http://platform.twitter.com/widgets.js';
			if ( is_ssl() )
				$script = str_replace( 'http://', 'https://', $script );
			wp_enqueue_script( 'twitter-widgets', $script, array(), '1.0.0', true );

			if ( ! function_exists( '_wp_footer_scripts' ) ) {
				// This means we can't just enqueue our script (fixes in WP 3.3)
				add_action( 'wp_footer', array( $this, 'add_twitter_js' ) );
			}
		}
		return $widgetContent;
	}

	private function _getTwitterLang() {
		$valid_langs = array(
			'en', // English
			'it', // Italian
			'es', // Spanish
			'fr', // French
			'ko', // Korean
			'ja', // Japanese
		);
		$locale = get_locale();
		$lang = strtolower( substr( get_locale(), 0, 2 ) );
		if ( in_array( $lang, $valid_langs ) )
			return $lang;

		return false;
	}

	public function add_twitter_js() {
		wp_print_scripts( 'twitter-widgets' );
	}

	/**
	 * Gets tweets, from cache if possible
	 *
	 * @param array $widgetOptions - options needed to get feeds
	 * @return array - Array of objects
	 */
	private function _getTweets( $widgetOptions ) {
		$key = 'twp_' . md5( maybe_serialize( $this->_get_feed_request_settings( $widgetOptions ) ) );
		return tlc_transient( $key )
			->expires_in( 300 ) // cache for 5 minutes
			->extend_on_fail( 120 ) // On a failed call, don't try again for 2 minutes
			->updates_with( array( $this, 'parseFeed' ), array( $widgetOptions ) )
			->get();
	}

	/**
	 * Pulls the JSON feed from Twitter and returns an array of objects
	 *
	 * @param array $widgetOptions - settings needed to get feed url, etc
	 * @return array
	 */
	public function parseFeed( $widgetOptions ) {
		$parameters = $this->_get_feed_request_settings( $widgetOptions );
		$response = array();

		if ( ! empty( $parameters['screen_name'] ) ) {
			if ( empty( $this->_settings['twp-authed-users'][strtolower( $parameters['screen_name'] )] ) ) {
				if ( empty( $widgetOptions['errmsg'] ) )
					$widgetOptions['errmsg'] = __( 'Account needs to be authorized', 'twitter-widget-pro' );
			} else {
				$this->_wp_twitter_oauth->set_token( $this->_settings['twp-authed-users'][strtolower( $parameters['screen_name'] )] );
				$response = $this->_wp_twitter_oauth->send_authed_request( 'statuses/user_timeline', 'GET', $parameters );
				if ( ! is_wp_error( $response ) )
					return $response;
			}
		} elseif ( ! empty( $parameters['list_id'] ) ) {
			$list_info = explode( '::', $widgetOptions['list'] );
			$user = array_shift( $list_info );
			$this->_wp_twitter_oauth->set_token( $this->_settings['twp-authed-users'][strtolower( $user )] );

			$response = $this->_wp_twitter_oauth->send_authed_request( 'lists/statuses', 'GET', $parameters );
			if ( ! is_wp_error( $response ) )
				return $response;
		}

		if ( empty( $widgetOptions['errmsg'] ) )
			$widgetOptions['errmsg'] = __( 'Invalid Twitter Response.', 'twitter-widget-pro' );
		do_action( 'widget_twitter_parsefeed_error', $response, $parameters, $widgetOptions );
		throw new Exception( $widgetOptions['errmsg'] );
	}

	/**
	 * Gets the parameters for the desired feed.
	 *
	 * @param array $widgetOptions - settings needed such as username, feet type, etc
	 * @return array - Parameters ready to pass to a Twitter request
	 */
	private function _get_feed_request_settings( $widgetOptions ) {
		/**
		 * user_id
		 * screen_name *
		 * since_id
		 * count
		 * max_id
		 * page
		 * trim_user
		 * include_rts *
		 * include_entities
		 * exclude_replies *
		 * contributor_details
		 */

		$parameters = array(
			'count'       => $widgetOptions['items'],
		);

		if ( ! empty( $widgetOptions['username'] ) ) {
			$parameters['screen_name'] = $widgetOptions['username'];
		} elseif ( ! empty( $widgetOptions['list'] ) ) {
			$list_info = explode( '::', $widgetOptions['list'] );
			$parameters['list_id'] = array_pop( $list_info );
		}

		if ( 'true' == $widgetOptions['hidereplies'] )
			$parameters['exclude_replies'] = 'true';

		if ( 'true' != $widgetOptions['showretweets'] )
			$parameters['include_rts'] = 'false';

		return $parameters;

	}

	/**
	 * Twitter displays all tweets that are less than 24 hours old with
	 * something like "about 4 hours ago" and ones older than 24 hours with a
	 * time and date. This function allows us to simulate that functionality,
	 * but lets us choose where the dividing line is.
	 *
	 * @param int $startTimestamp - The timestamp used to calculate time passed
	 * @param int $max - Max number of seconds to conver to "ago" messages.  0 for all, -1 for none
	 * @return string
	 */
	private function _timeSince( $startTimestamp, $max, $dateFormat ) {
		// array of time period chunks
		$chunks = array(
			'year'   => 60 * 60 * 24 * 365, // 31,536,000 seconds
			'month'  => 60 * 60 * 24 * 30,  // 2,592,000 seconds
			'week'   => 60 * 60 * 24 * 7,   // 604,800 seconds
			'day'    => 60 * 60 * 24,       // 86,400 seconds
			'hour'   => 60 * 60,            // 3600 seconds
			'minute' => 60,                 // 60 seconds
			'second' => 1                   // 1 second
		);

		$since = time() - $startTimestamp;

		if ( $max != '-1' && $since >= $max )
			return date_i18n( $dateFormat, $startTimestamp + get_option('gmt_offset') * 3600 );


		foreach ( $chunks as $key => $seconds ) {
			// finding the biggest chunk ( if the chunk fits, break )
			if ( ( $count = floor( $since / $seconds ) ) != 0 )
				break;
		}

		$messages = array(
			'year'   => _n( 'about %s year ago',   'about %s years ago',   $count, 'twitter-widget-pro' ),
			'month'  => _n( 'about %s month ago',  'about %s months ago',  $count, 'twitter-widget-pro' ),
			'week'   => _n( 'about %s week ago',   'about %s weeks ago',   $count, 'twitter-widget-pro' ),
			'day'    => _n( 'about %s day ago',    'about %s days ago',    $count, 'twitter-widget-pro' ),
			'hour'   => _n( 'about %s hour ago',   'about %s hours ago',   $count, 'twitter-widget-pro' ),
			'minute' => _n( 'about %s minute ago', 'about %s minutes ago', $count, 'twitter-widget-pro' ),
			'second' => _n( 'about %s second ago', 'about %s seconds ago', $count, 'twitter-widget-pro' ),
		);

		return sprintf( $messages[$key], $count );
	}

	/**
	 * Returns the Twitter user's profile image, linked to that user's profile
	 *
	 * @param object $user - Twitter User
	 * @param array $args - Widget Arguments
	 * @return string - Linked image ( XHTML )
	 */
	private function _getProfileImage( $user, $args = array() ) {
		$linkAttrs = array(
			'href'  => "http://twitter.com/{$user->screen_name}",
			'title' => $user->name
		);
		$replace = ( 'original' == $args['avatar'] )? '.':"_{$args['avatar']}.";
		$img = str_replace( '_normal.', $replace, $user->profile_image_url_https );

		return $this->_buildLink( "<img alt='{$user->name}' src='{$img}' />", $linkAttrs, true );
	}

    /**
	 * Replace our shortCode with the "widget"
	 *
	 * @param array $attr - array of attributes from the shortCode
	 * @param string $content - Content of the shortCode
	 * @return string - formatted XHTML replacement for the shortCode
	 */
    public function handleShortcodes( $attr, $content = '' ) {
		$defaults = array(
			'before_widget'   => '',
			'after_widget'    => '',
			'before_title'    => '<h2>',
			'after_title'     => '</h2>',
			'title'           => '',
			'errmsg'          => '',
			'username'        => '',
			'list'            => '',
			'hidereplies'     => 'false',
			'showretweets'    => 'true',
			'hidefrom'        => 'false',
			'showintents'     => 'true',
			'showfollow'      => 'true',
			'avatar'          => '',
			'targetBlank'     => 'false',
			'items'           => 10,
			'showts'          => 60 * 60 * 24,
			'dateFormat'      => __( 'h:i:s A F d, Y', 'twitter-widget-pro' ),
		);

		/**
		 * Attribute names are strtolower'd, so we need to fix them to match
		 * the names used through the rest of the plugin
		 */
		if ( array_key_exists( 'targetblank', $attr ) ) {
			$attr['targetBlank'] = $attr['targetblank'];
			unset( $attr['targetblank'] );
		}
		if ( array_key_exists( 'dateformat', $attr ) ) {
			$attr['dateFormat'] = $attr['dateformat'];
			unset( $attr['dateformat'] );
		}

		if ( !empty( $content ) && empty( $attr['title'] ) )
			$attr['title'] = $content;


        $attr = shortcode_atts( $defaults, $attr );

		if ( $attr['hidereplies'] && $attr['hidereplies'] != 'false' && $attr['hidereplies'] != '0' )
			$attr['hidereplies'] = 'true';

		if ( $attr['showretweets'] && $attr['showretweets'] != 'false' && $attr['showretweets'] != '0' )
			$attr['showretweets'] = 'true';

		if ( $attr['hidefrom'] && $attr['hidefrom'] != 'false' && $attr['hidefrom'] != '0' )
			$attr['hidefrom'] = 'true';

		if ( $attr['showintents'] && $attr['showintents'] != 'true' && $attr['showintents'] != '1' )
			$attr['showintents'] = 'false';

		if ( $attr['showfollow'] && $attr['showfollow'] != 'true' && $attr['showfollow'] != '1' )
			$attr['showfollow'] = 'false';

		if ( !in_array( $attr['avatar'], array( 'bigger', 'normal', 'mini', 'original', '' ) ) )
			$attr['avatar'] = 'normal';

		if ( $attr['targetBlank'] && $attr['targetBlank'] != 'false' && $attr['targetBlank'] != '0' )
			$attr['targetBlank'] = 'true';

		return $this->display( $attr );
	}

	public function authed_users_option( $settings ) {
		if ( ! is_array( $settings ) )
			return array();
		return $settings;
	}

	public function filterSettings( $settings ) {
		$defaultArgs = array(
			'consumer-key'    => '',
			'consumer-secret' => '',
			'title'           => '',
			'errmsg'          => '',
			'username'        => '',
			'list'            => '',
			'http_vs_https'   => 'https',
			'hidereplies'     => 'false',
			'showretweets'    => 'true',
			'hidefrom'        => 'false',
			'showintents'     => 'true',
			'showfollow'      => 'true',
			'avatar'          => '',
			'targetBlank'     => 'false',
			'items'           => 10,
			'showts'          => 60 * 60 * 24,
			'dateFormat'      => __( 'h:i:s A F d, Y', 'twitter-widget-pro' ),
		);

		return $this->fixAvatar( wp_parse_args( $settings, $defaultArgs ) );
	}

	/**
	 * Now that we support all the profile image sizes we need to convert
	 * the old true/false to a size string
	 */
	private function fixAvatar( $settings ) {
		if ( false === $settings['avatar'] )
			$settings['avatar'] = '';
		elseif ( !in_array( $settings['avatar'], array( 'bigger', 'normal', 'mini', 'original', false ) ) )
			$settings['avatar'] = 'normal';

		return $settings;
	}

	public function getSettings( $settings ) {
		return $this->fixAvatar( wp_parse_args( $settings, $this->_settings['twp'] ) );
	}

	public function get_users_list( $authed = false ) {
		$users = $this->_settings['twp-authed-users'];
		if ( $authed ) {
			if ( ! empty( $this->_authed_users ) )
				return $this->_authed_users;
			foreach ( $users as $key => $u ) {
				$this->_wp_twitter_oauth->set_token( $u );
				$rates = $this->_wp_twitter_oauth->send_authed_request( 'application/rate_limit_status', 'GET', array( 'resources' => 'statuses,lists' ) );
				if ( is_wp_error( $rates ) )
					unset( $users[$key] );
			}
			$this->_authed_users = $users;
		}
		return $users;
	}

	public function get_lists() {
		if ( ! empty( $this->_lists ) )
			return $this->_lists;
		$this->_lists =  array();
		foreach ( $this->_settings['twp-authed-users'] as $key => $u ) {
			$this->_wp_twitter_oauth->set_token( $u );
			$user_lists = $this->_wp_twitter_oauth->send_authed_request( 'lists/list', 'GET', array( 'resources' => 'statuses,lists' ) );

			if ( ! empty( $user_lists ) && ! is_wp_error( $user_lists ) ) {
				$this->_lists[$key] = array();
				foreach ( $user_lists as $l ) {
					$this->_lists[$key][$l->id] = $l->name;
				}
			}
		}
		return $this->_lists;
	}

	public function init() {
		if ( isset( $_GET['twp-test-local-request'] ) && ! empty( $_POST['_twp-test-local-request'] ) && 'test' === $_POST['_twp-test-local-request'] ) {
			die( 'success' );
		}
	}

	public function init_locale() {
		load_plugin_textdomain( 'twitter-widget-pro' );
	}

	protected function _get_settings() {
		foreach ( $this->_optionNames as $opt ) {
			$this->_settings[$opt] = apply_filters( 'twitter-widget-pro-opt-'.$opt, get_option($opt));
		}
	}

	public function register_options() {
		foreach ( $this->_optionNames as $opt ) {
			if ( !empty($this->_optionCallbacks[$opt]) && is_callable( $this->_optionCallbacks[$opt] ) ) {
				$callback = $this->_optionCallbacks[$opt];
			} else {
				$callback = '';
			}
			register_setting( $this->_optionGroup, $opt, $callback );
		}
	}

	public function changelog ($pluginData, $newPluginData) {
		require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );

		$plugin = plugins_api( 'plugin_information', array( 'slug' => $newPluginData->slug ) );

		if ( !$plugin || is_wp_error( $plugin ) || empty( $plugin->sections['changelog'] ) ) {
			return;
		}

		$changes = $plugin->sections['changelog'];
		$pos = strpos( $changes, '<h4>' . preg_replace('/[^\d\.]/', '', $pluginData['Version'] ) );
		if ( $pos !== false ) {
			$changes = trim( substr( $changes, 0, $pos ) );
		}

		$replace = array(
			'<ul>'	=> '<ul style="list-style: disc inside; padding-left: 15px; font-weight: normal;">',
			'<h4>'	=> '<h4 style="margin-bottom:0;">',
		);
		echo str_replace( array_keys($replace), $replace, $changes );
	}

	public function register_options_page() {
		add_options_page( $this->_pageTitle, $this->_menuTitle, $this->_accessLevel, $this->_hook, array( $this, 'options_page' ) );
	}

	protected function _filter_boxes_main($boxName) {
		if ( 'main' == strtolower($boxName) )
			return false;

		return $this->_filter_boxes_helper($boxName, 'main');
	}

	protected function _filter_boxes_sidebar($boxName) {
		return $this->_filter_boxes_helper($boxName, 'sidebar');
	}

	protected function _filter_boxes_helper($boxName, $test) {
		return ( strpos( strtolower($boxName), strtolower($test) ) !== false );
	}

	public function options_page() {
		global $wp_meta_boxes;
		$allBoxes = array_keys( $wp_meta_boxes['aaron-twitter-widget-pro'] );
		$mainBoxes = array_filter( $allBoxes, array( $this, '_filter_boxes_main' ) );
		unset($mainBoxes['main']);
		sort($mainBoxes);
		$sidebarBoxes = array_filter( $allBoxes, array( $this, '_filter_boxes_sidebar' ) );
		unset($sidebarBoxes['sidebar']);
		sort($sidebarBoxes);

		$main_width = empty( $sidebarBoxes )? '100%' : '75%';
		?>
			<div class="wrap">
				<h2><?php echo esc_html($this->_pageTitle); ?></h2>
				<div class="metabox-holder">
					<div class="postbox-container" style="width:<?php echo $main_width; ?>;">
					<?php
						do_action( 'rpf-pre-main-metabox', $main_width );
						if ( in_array( 'main', $allBoxes ) ) {
					?>
						<form action="<?php esc_attr_e( $this->_optionsPageAction ); ?>" method="post"<?php do_action( 'rpf-options-page-form-tag' ) ?>>
							<?php
							settings_fields( $this->_optionGroup );
							do_meta_boxes( 'aaron-twitter-widget-pro', 'main', '' );
							?>
							<p class="submit">
								<input type="submit" name="Submit" value="<?php esc_attr_e( 'Update Options &raquo;', 'twitter-widget-pro' ); ?>" />
							</p>
						</form>
					<?php
						}
						foreach( $mainBoxes as $context ) {
							do_meta_boxes( 'aaron-twitter-widget-pro', $context, '' );
						}
					?>
					</div>
					<?php
					if ( !empty( $sidebarBoxes ) ) {
					?>
					<div class="alignright" style="width:24%;">
						<?php
						foreach( $sidebarBoxes as $context ) {
							do_meta_boxes( 'aaron-twitter-widget-pro', $context, '' );
						}
						?>
					</div>
					<?php
					}
					?>
				</div>
			</div>
			<?php
	}

	public function add_plugin_page_links( $links, $file ){
		if ( $file == $this->_file ) {
			// Add Widget Page link to our plugin
			$link = $this->get_options_link();
			array_unshift( $links, $link );

			// Add Support Forum link to our plugin
			$link = $this->get_support_forum_link();
			array_unshift( $links, $link );
		}
		return $links;
	}

	public function add_plugin_meta_links( $meta, $file ){
		if ( $file == $this->_file )
			$meta[] = $this->get_plugin_link(__('Rate Plugin'));
		return $meta;
	}

	public function get_support_forum_link( $linkText = '' ) {
		if ( empty($linkText) ) {
			$linkText = __( 'Support', 'twitter-widget-pro' );
		}
		return '<a href="' . $this->get_support_forum_url() . '">' . $linkText . '</a>';
	}

	public function get_support_forum_url() {
		return 'http://wordpress.org/support/plugin/twitter-widget-pro';
	}

	public function get_plugin_link( $linkText = '' ) {
		if ( empty($linkText) )
			$linkText = __( 'Give it a good rating on WordPress.org.', 'twitter-widget-pro' );
		return "<a href='" . $this->get_plugin_url() . "'>{$linkText}</a>";
	}

	public function get_plugin_url() {
		return 'http://wordpress.org/extend/plugins/twitter-widget-pro';
	}

	public function get_options_link( $linkText = '' ) {
		if ( empty($linkText) ) {
			$linkText = __( 'Settings', 'twitter-widget-pro' );
		}
		return '<a href="' . $this->get_options_url() . '">' . $linkText . '</a>';
	}

	public function get_options_url() {
		return admin_url( 'options-general.php?page=' . $this->_hook );
	}

	public function admin_enqueue_scripts() {
		if (isset($_GET['page']) && $_GET['page'] == $this->_hook) {
			wp_enqueue_style('dashboard');
		}
	}

	public function add_default_options_meta_boxes() {
		if ( apply_filters( 'show-aaron-like-this', true ) )
			add_meta_box( 'twitter-widget-pro-like-this', __('Like this Plugin?', 'twitter-widget-pro'), array($this, 'like_this_meta_box'), 'aaron-twitter-widget-pro', 'sidebar');

		if ( apply_filters( 'show-aaron-support', true ) )
			add_meta_box( 'twitter-widget-pro-support', __('Need Support?', 'twitter-widget-pro'), array($this, 'support_meta_box'), 'aaron-twitter-widget-pro', 'sidebar');

		if ( apply_filters( 'show-aaron-feed', true ) )
			add_meta_box( 'twitter-widget-pro-aaron-feed', __('Latest news from Aaron', 'twitter-widget-pro'), array($this, 'aaron_feed_meta_box'), 'aaron-twitter-widget-pro', 'sidebar');
	}

	public function like_this_meta_box() {
		echo '<p>';
		_e('Then please do any or all of the following:', 'twitter-widget-pro');
		echo '</p><ul>';

		echo "<li><a href='https://aarondcampbell.com/wordpress-plugin/twitter-widget-pro'>";
		_e('Link to it so others can find out about it.', 'twitter-widget-pro');
		echo "</a></li>";

		echo '<li>' . $this->get_plugin_link() . '</li>';

		echo '</ul>';
	}

	public function support_meta_box() {
		echo '<p>';
		echo sprintf(__('If you have any problems with this plugin or ideas for improvements or enhancements, please use the <a href="%s">Support Forums</a>.', 'twitter-widget-pro'), $this->get_support_forum_url() );
		echo '</p>';
	}

	public function aaron_feed_meta_box() {
		$args = array(
			'url'			=> $this->_feed_url,
			'items'			=> '5',
		);
		echo '<div class="rss-widget">';
		wp_widget_rss_output( $args );
		echo "</div>";
	}

	public function admin_print_scripts() {
		if (isset($_GET['page']) && $_GET['page'] == $this->_hook) {
			wp_enqueue_script('postbox');
			wp_enqueue_script('dashboard');
		}
	}

}
// Instantiate our class
$wpTwitterWidget = wpTwitterWidget::getInstance();
