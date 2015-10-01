<?php
/**
 * WP_Widget_Twitter_Pro is the class that handles the main widget.
 */
class WP_Widget_Twitter_Pro extends WP_Widget {
	public function __construct () {
		$wpTwitterWidget = wpTwitterWidget::getInstance();
		$widget_ops = array(
			'classname' => 'widget_twitter',
			'description' => __( 'Follow a Twitter Feed', 'twitter-widget-pro' )
		);
		$control_ops = array(
			'width' => 400,
			'height' => 350,
			'id_base' => 'twitter'
		);
		$name = __( 'Twitter Widget Pro', 'twitter-widget-pro' );

		parent::__construct( 'twitter', $name, $widget_ops, $control_ops );
	}

	private function _getInstanceSettings ( $instance ) {
		$wpTwitterWidget = wpTwitterWidget::getInstance();
		return $wpTwitterWidget->getSettings( $instance );
	}

	public function form( $instance ) {
		$instance = $this->_getInstanceSettings( $instance );
		$wpTwitterWidget = wpTwitterWidget::getInstance();
		$users = $wpTwitterWidget->get_users_list( true );
		$lists = $wpTwitterWidget->get_lists();

?>
			<p>
				<label for="<?php echo $this->get_field_id( 'username' ); ?>"><?php _e( 'Twitter username:', 'twitter-widget-pro' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'username' ); ?>" name="<?php echo $this->get_field_name( 'username' ); ?>">
					<option></option>
					<?php
					$selected = false;
					foreach ( $users as $u ) {
						?>
						<option value="<?php echo esc_attr( strtolower( $u['screen_name'] ) ); ?>"<?php $s = selected( strtolower( $u['screen_name'] ), strtolower( $instance['username'] ) ) ?>><?php echo esc_html( $u['screen_name'] ); ?></option>
						<?php
						if ( ! empty( $s ) )
							$selected = true;
					}
					?>
				</select>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'list' ); ?>"><?php _e( 'Twitter list:', 'twitter-widget-pro' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'list' ); ?>" name="<?php echo $this->get_field_name( 'list' ); ?>">
					<option></option>
					<?php
					foreach ( $lists as $user => $user_lists ) {
						echo '<optgroup label="' . esc_attr( $user ) . '">';
						foreach ( $user_lists as $list_id => $list_name ) {
							?>
							<option value="<?php echo esc_attr( $user . '::' . $list_id ); ?>"<?php $s = selected( $user . '::' . $list_id, strtolower( $instance['list'] ) ) ?>><?php echo esc_html( $list_name ); ?></option>
							<?php
						}
						echo '</optgroup>';
					}
					?>
				</select>
			</p>
			<?php
			if ( ! $selected && ! empty( $instance['username'] ) ) {
				$query_args = array(
					'action' => 'authorize',
					'screen_name' => $instance['username'],
				);
				$authorize_user_url = wp_nonce_url( add_query_arg( $query_args, $wpTwitterWidget->get_options_url() ), 'authorize' );
				?>
			<p>
				<a href="<?php echo esc_url( $authorize_user_url ); ?>" style="color:red;">
					<?php _e( 'You need to authorize this account.', 'twitter-widget-pro' ); ?>
				</a>
			</p>
				<?php
			}
			?>
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Give the feed a title ( optional ):', 'twitter-widget-pro' ); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php esc_attr_e( $instance['title'] ); ?>" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'items' ); ?>"><?php _e( 'How many items would you like to display?', 'twitter-widget-pro' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'items' ); ?>" name="<?php echo $this->get_field_name( 'items' ); ?>">
					<?php
						for ( $i = 1; $i <= 20; ++$i ) {
							echo "<option value='$i' ". selected( $instance['items'], $i, false ). ">$i</option>";
						}
					?>
				</select>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'avatar' ); ?>"><?php _e( 'Display profile image?', 'twitter-widget-pro' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'avatar' ); ?>" name="<?php echo $this->get_field_name( 'avatar' ); ?>">
					<option value=""<?php selected( $instance['avatar'], '' ) ?>><?php _e( 'Do not show', 'twitter-widget-pro' ); ?></option>
					<option value="mini"<?php selected( $instance['avatar'], 'mini' ) ?>><?php _e( 'Mini - 24px by 24px', 'twitter-widget-pro' ); ?></option>
					<option value="normal"<?php selected( $instance['avatar'], 'normal' ) ?>><?php _e( 'Normal - 48px by 48px', 'twitter-widget-pro' ); ?></option>
					<option value="bigger"<?php selected( $instance['avatar'], 'bigger' ) ?>><?php _e( 'Bigger - 73px by 73px', 'twitter-widget-pro' ); ?></option>
					<option value="original"<?php selected( $instance['avatar'], 'original' ) ?>><?php _e( 'Original', 'twitter-widget-pro' ); ?></option>
				</select>
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'showretweets' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'showretweets' ); ?>" name="<?php echo $this->get_field_name( 'showretweets' ); ?>"<?php checked( $instance['showretweets'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'showretweets' ); ?>"><?php _e( 'Include retweets', 'twitter-widget-pro' ); ?></label>
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'hidereplies' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'hidereplies' ); ?>" name="<?php echo $this->get_field_name( 'hidereplies' ); ?>"<?php checked( $instance['hidereplies'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'hidereplies' ); ?>"><?php _e( 'Hide @replies', 'twitter-widget-pro' ); ?></label>
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'hidefrom' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'hidefrom' ); ?>" name="<?php echo $this->get_field_name( 'hidefrom' ); ?>"<?php checked( $instance['hidefrom'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'hidefrom' ); ?>"><?php _e( 'Hide sending applications', 'twitter-widget-pro' ); ?></label>
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'showintents' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'showintents' ); ?>" name="<?php echo $this->get_field_name( 'showintents' ); ?>"<?php checked( $instance['showintents'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'showintents' ); ?>"><?php _e( 'Show Tweet Intents (reply, retweet, favorite)', 'twitter-widget-pro' ); ?></label>
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'showfollow' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'showfollow' ); ?>" name="<?php echo $this->get_field_name( 'showfollow' ); ?>"<?php checked( $instance['showfollow'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'showfollow' ); ?>"><?php _e( 'Show Follow Link', 'twitter-widget-pro' ); ?></label>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'errmsg' ); ?>"><?php _e( 'What to display when Twitter is down ( optional ):', 'twitter-widget-pro' ); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'errmsg' ); ?>" name="<?php echo $this->get_field_name( 'errmsg' ); ?>" type="text" value="<?php esc_attr_e( $instance['errmsg'] ); ?>" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'showts' ); ?>"><?php _e( 'Show date/time of Tweet ( rather than 2 ____ ago ):', 'twitter-widget-pro' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'showts' ); ?>" name="<?php echo $this->get_field_name( 'showts' ); ?>">
					<option value="0" <?php selected( $instance['showts'], '0' ); ?>><?php _e( 'Always', 'twitter-widget-pro' );?></option>
					<option value="3600" <?php selected( $instance['showts'], '3600' ); ?>><?php _e( 'If over an hour old', 'twitter-widget-pro' );?></option>
					<option value="86400" <?php selected( $instance['showts'], '86400' ); ?>><?php _e( 'If over a day old', 'twitter-widget-pro' );?></option>
					<option value="604800" <?php selected( $instance['showts'], '604800' ); ?>><?php _e( 'If over a week old', 'twitter-widget-pro' );?></option>
					<option value="2592000" <?php selected( $instance['showts'], '2592000' ); ?>><?php _e( 'If over a month old', 'twitter-widget-pro' );?></option>
					<option value="31536000" <?php selected( $instance['showts'], '31536000' ); ?>><?php _e( 'If over a year old', 'twitter-widget-pro' );?></option>
					<option value="-1" <?php selected( $instance['showts'], '-1' ); ?>><?php _e( 'Never', 'twitter-widget-pro' );?></option>
				</select>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'dateFormat' ); ?>"><?php echo sprintf( __( 'Format to display the date in, uses <a href="%s">PHP date()</a> format:', 'twitter-widget-pro' ), 'http://php.net/date' ); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'dateFormat' ); ?>" name="<?php echo $this->get_field_name( 'dateFormat' ); ?>" type="text" value="<?php esc_attr_e( $instance['dateFormat'] ); ?>" />
			</p>
			<p>
				<input type="hidden" value="false" name="<?php echo $this->get_field_name( 'targetBlank' ); ?>" />
				<input class="checkbox" type="checkbox" value="true" id="<?php echo $this->get_field_id( 'targetBlank' ); ?>" name="<?php echo $this->get_field_name( 'targetBlank' ); ?>"<?php checked( $instance['targetBlank'], 'true' ); ?> />
				<label for="<?php echo $this->get_field_id( 'targetBlank' ); ?>"><?php _e( 'Open links in a new window', 'twitter-widget-pro' ); ?></label>
			</p>
			<p><?php echo $wpTwitterWidget->get_support_forum_link(); ?></p>
			<script type="text/javascript">
				jQuery( '#<?php echo $this->get_field_id( 'username' ) ?>' ).on( 'change', function() {
					jQuery('#<?php echo $this->get_field_id( 'list' ) ?>' ).val(0);
				});
				jQuery( '#<?php echo $this->get_field_id( 'list' ) ?>' ).on( 'change', function() {
					jQuery('#<?php echo $this->get_field_id( 'username' ) ?>' ).val(0);
				});
			</script>
<?php
		return;
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $this->_getInstanceSettings( $new_instance );

		// Clean up the free-form areas
		$instance['title'] = stripslashes( $new_instance['title'] );
		$instance['errmsg'] = stripslashes( $new_instance['errmsg'] );

		// If the current user isn't allowed to use unfiltered HTML, filter it
		if ( !current_user_can( 'unfiltered_html' ) ) {
			$instance['title'] = strip_tags( $new_instance['title'] );
			$instance['errmsg'] = strip_tags( $new_instance['errmsg'] );
		}

		return $instance;
	}

	public function flush_widget_cache() {
		wp_cache_delete( 'widget_twitter_widget_pro', 'widget' );
	}

	public function widget( $args, $instance ) {
		$instance = $this->_getInstanceSettings( $instance );
		$wpTwitterWidget = wpTwitterWidget::getInstance();
		echo $wpTwitterWidget->display( wp_parse_args( $instance, $args ) );
	}
}
