<?php
/**
* Plugin Name: 3D Avatar User Profile
* Plugin URI: https://avatar3dcreator.com/wordpress-plugin-3d-avatar-user-profile/
* Description: Adds customizable 3D avatar to user profiles or 3D Gravatar image.
* Version: 1.0.0
* Author: Avatar 3D Creator
* Author URI: https:/avatar3dcreator.com/
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: 3d-avatar-user-profile
*/

define('AV3DUP_PLUGIN_DIR', WP_PLUGIN_URL . '/' . plugin_basename( dirname(__FILE__) ) . '/' );

global $avatar_3d_user_profile;
$avatar_3d_user_profile = new avatar_3d_user_profile();

function get_avatar_3d( $id_or_email, $size = 96, $default = '', $alt = '', $args = array() ) {
	return apply_filters( 'avatar_3d', get_avatar( $id_or_email, $size, $default, $alt, $args ) );
}

register_uninstall_hook( __FILE__, 'avatar_3d_user_profile_uninstall' );

function avatar_3d_user_profile_uninstall() {
	$avatar_3d_user_profile = new avatar_3d_user_profile();
	$users                = get_users(
		array(
			'meta_key' => 'avatar_3d',
			'fields'   => 'ids',
		)
	);

	foreach ( $users as $user_id ) :
		$avatar_3d_user_profile->avatar_delete( $user_id );
	endforeach;

	delete_option( 'avatar_3d_user_profile' );
}


class avatar_3d_user_profile {
	private $user_id_being_edited, $avatar_upload_error, $remove_nonce, $avatar_ratings;
	public $options;

	public function __construct() {
		$this->options        = (array) get_option( 'avatar_3d_user_profile' );
		$this->avatar_ratings = array(
			'G'  => __( 'G &#8212; Suitable for all audiences', 'avatar-3d-user-profile' ),
			'PG' => __( 'PG &#8212; Possibly offensive, usually for audiences 13 and above', 'avatar-3d-user-profile' ),
			'R'  => __( 'R &#8212; Intended for adult audiences above 17', 'avatar-3d-user-profile' ),
			'X'  => __( 'X &#8212; Even more mature than above', 'avatar-3d-user-profile' ),
		);

		$this->add_hooks();
	}

	public function add_hooks() {
		add_filter( 'pre_get_avatar_data', array( $this, 'get_avatar_data' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'admin_init' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ) );
		add_action( 'show_user_profile', array( $this, 'edit_user_profile' ) );
		
		// enrican 271221 todo
		// add_action( 'admin_notices', array( $this, 'edit_user_profile' ) );
		// enrican 271221

		add_action( 'personal_options_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'admin_action_remove-avatar-3d', array( $this, 'action_remove_avatar_3d' ) );
		add_action( 'wp_ajax_assign_avatar_3d_media', array( $this, 'ajax_assign_avatar_3d_media' ) );
		add_action( 'wp_ajax_remove_avatar_3d', array( $this, 'action_remove_avatar_3d' ) );
		add_action( 'user_edit_form_tag', array( $this, 'user_edit_form_tag' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		
	}

	public function get_avatar( $avatar = '', $id_or_email = '', $size = 96, $default = '', $alt = '', $args = array() ) {
		return apply_filters( 'avatar_3d', get_avatar( $id_or_email, $size, $default, $alt, $args ) );
	}

	public function get_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$avatar_3d_url = $this->get_avatar_3d_url( $id_or_email, $args['size'] );
		if ( $avatar_3d_url ) {
			$args['url'] = $avatar_3d_url;
		}

		if ( ! $avatar_3d_url && ! empty( $this->options['only'] ) ) {
			$args['url'] = $this->get_default_avatar_url( $args['size'] );
		}

		if ( ! empty( $args['url'] ) ) {
			$args['found_avatar'] = true;
		}

		return $args;
	}

	public function get_avatar_3d_url( $id_or_email, $size ) {
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( is_string( $id_or_email ) && ( $user = get_user_by( 'email', $id_or_email ) ) ) {
			$user_id = $user->ID;
		} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( $id_or_email instanceof WP_Post && ! empty( $id_or_email->post_author ) ) {
			$user_id = (int) $id_or_email->post_author;
		}

		if ( empty( $user_id ) ) {
			return '';
		}

		$local_avatars = get_user_meta( $user_id, 'avatar_3d', true );
		if ( empty( $local_avatars['full'] ) ) {
			return '';
		}

		$avatar_rating = get_user_meta( $user_id, 'avatar_3d_rating', true );
		if ( ! empty( $avatar_rating ) && 'G' !== $avatar_rating && ( $site_rating = get_option( 'avatar_rating' ) ) ) {
			$ratings              = array_keys( $this->avatar_ratings );
			$site_rating_weight   = array_search( $site_rating, $ratings );
			$avatar_rating_weight = array_search( $avatar_rating, $ratings );
			if ( false !== $avatar_rating_weight && $avatar_rating_weight > $site_rating_weight ) {
				return '';
			}
		}

		if ( ! empty( $local_avatars['media_id'] ) ) {
			if ( ! $avatar_full_path = get_attached_file( $local_avatars['media_id'] ) ) {
				return '';
			}
		}

		$size = (int) $size;

		if ( ! array_key_exists( $size, $local_avatars ) ) {
			$local_avatars[ $size ] = $local_avatars['full'];

			if ( apply_filters( 'avatar_3d_user_profile_dynamic_resize', true ) ) :

				$upload_path = wp_upload_dir();

				if ( ! isset( $avatar_full_path ) ) {
					$avatar_full_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $local_avatars['full'] );
				}

				$editor = wp_get_image_editor( $avatar_full_path );
				if ( ! is_wp_error( $editor ) ) {
					$resized = $editor->resize( $size, $size, true );
					if ( ! is_wp_error( $resized ) ) {
						$dest_file = $editor->generate_filename();
						$saved     = $editor->save( $dest_file );
						if ( ! is_wp_error( $saved ) ) {
							$local_avatars[ $size ] = str_replace( $upload_path['basedir'], $upload_path['baseurl'], $dest_file );
						}
					}
				}

				// save avatar
				update_user_meta( $user_id, 'avatar_3d', $local_avatars );

			endif;
		}

		if ( 'http' !== substr( $local_avatars[ $size ], 0, 4 ) ) {
			$local_avatars[ $size ] = home_url( $local_avatars[ $size ] );
		}

		return esc_url( $local_avatars[ $size ] );
	}

	public function get_default_avatar_url( $size ) {
		if ( empty( $default ) ) {
			$avatar_default = get_option( 'avatar_default' );
			if ( empty( $avatar_default ) ) {
				$default = 'mystery';
			} else {
				$default = $avatar_default;
			}
		}

		$host = is_ssl() ? 'https://secure.gravatar.com' : 'http://0.gravatar.com';

		if ( 'mystery' === $default ) {
			$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
		} elseif ( 'blank' === $default ) {
			$default = includes_url( 'images/blank.gif' );
		} elseif ( 'gravatar_default' === $default ) {
			$default = "$host/avatar/?s={$size}";
		} else {
			$default = "$host/avatar/?d=$default&amp;s={$size}";
		}

		return $default;
	}

	public function admin_init() {
		if ( $old_ops = get_option( 'avatar_3d_user_profile_caps' ) ) {
			if ( ! empty( $old_ops['avatar_3d_user_profile_caps'] ) ) {
				update_option( 'avatar_3d_user_profile', array( 'caps' => 1 ) );
			}

			delete_option( 'avatar_3d_caps' );
		}

		register_setting( 'discussion', 'avatar_3d_user_profile', array( $this, 'sanitize_options' ) );
		add_settings_field(
			'avatar-3d-user-profile-only',
			__( 'Local Avatars Only', 'avatar-3d-user-profile' ),
			array( $this, 'avatar_settings_field' ),
			'discussion',
			'avatars',
			array(
				'key'  => 'only',
				'desc' => __( 'Only allow local avatars (still uses Gravatar for default avatars)', 'avatar-3d-user-profile' ),
			)
		);
		add_settings_field(
			'avatar-3d-user-profile-caps',
			__( 'Local Upload Permissions', 'avatar-3d-user-profile' ),
			array( $this, 'avatar_settings_field' ),
			'discussion',
			'avatars',
			array(
				'key'  => 'caps',
				'desc' => __( 'Only allow users with file upload capabilities to upload local avatars (Authors and above)', 'avatar-3d-user-profile' ),
			)
		);
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( 'profile.php' !== $hook_suffix && 'user-edit.php' !== $hook_suffix ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		$user_id = ( 'profile.php' === $hook_suffix ) ? get_current_user_id() : (int) $_GET['user_id'];

		$this->remove_nonce = wp_create_nonce( 'remove_avatar_3d_nonce' );

		// enrican 261221 todo
		$dev = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.dev' : '';
		wp_enqueue_script( 'avatar-3d-user-profile', AV3DUP_PLUGIN_DIR . 'js/avatar-3d-user-profile' . $dev . '.js', array( 'jquery' ), false, true );
		
	    // enrican 281221 enqueque threejs
        wp_enqueue_script( 'threejs', AV3DUP_PLUGIN_DIR . 'js/three.min.js', null, $this->version, false );
        wp_enqueue_script( 'gltf-loader', AV3DUP_PLUGIN_DIR . 'js/GLTFLoader.js', array( 'threejs' ), $this->version, false );
        wp_enqueue_script( 'orbitcontrols', AV3DUP_PLUGIN_DIR . 'js/OrbitControls.js', array( 'threejs' ), $this->version, false );
        
        // enrican 281221 jquery
        wp_register_script( 'jquery-script', AV3DUP_PLUGIN_DIR . 'js/jquery-script.js', array('jquery'));
	    wp_enqueue_script( 'jquery-script' );
	    
	    // enrican 291221 css
	    wp_register_style( 'css-script', AV3DUP_PLUGIN_DIR . 'css/style.css' );
	    wp_enqueue_style('css-script');
	    
	    wp_enqueue_script( 'default-avatar', AV3DUP_PLUGIN_DIR . 'js/default-avatar.js', null, $this->version, true );
	    
	    // enrican 291221 pass var php to js
	    wp_localize_script( 'default-avatar', 'PHPTOJS', 
		  	array( 
				'PLUGINDIR' => AV3DUP_PLUGIN_DIR,
				'GRAVATARIMG' => get_avatar_url(get_current_user_id())
			) 
		);
		
		wp_localize_script(
			'avatar-3d-user-profile',
			'i10n_Avatar3dUserProfile',
			array(
				'user_id'          => $user_id,
				'insertMediaTitle' => __( 'Choose an Avatar', 'avatar-3d-user-profile' ),
				'insertIntoPost'   => __( 'Set as avatar', 'avatar-3d-user-profile' ),
				'deleteNonce'      => $this->remove_nonce,
				'mediaNonce'       => wp_create_nonce( 'assign_avatar_3d_nonce' ),
			)
		);
	}

	public function sanitize_options( $input ) {
		$new_input['caps'] = empty( $input['caps'] ) ? 0 : 1;
		$new_input['only'] = empty( $input['only'] ) ? 0 : 1;
		return $new_input;
	}

	public function avatar_settings_field( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'key'  => '',
				'desc' => '',
			)
		);

		if ( empty( $this->options[ $args['key'] ] ) ) {
			$this->options[ $args['key'] ] = 0;
		}

		echo '
			<label for="avatar-3d-user-profile-' . esc_attr( $args['key'] ) . '">
				<input type="checkbox" name="avatar_3d_user_profile[' . esc_attr( $args['key'] ) . ']" id="avatar-3d-user-profile-' . esc_attr( $args['key'] ) . '" value="1" ' . checked( $this->options[ $args['key'] ], 1, false ) . ' />
				' . esc_html( $args['desc'] ) . '
			</label>
		';
	}

    // output 3d avatar
	public function edit_user_profile( $profileuser ) {
		
	// enrican 261221
	// echo do_shortcode('[3d_viewer src="https://3davatar.info/wp-content/uploads/2021/12/047.glb"]');
	// echo get_avatar_url(get_current_user_id()); 
	
	$currenturl = add_query_arg( NULL, NULL );
	
	?>
	
	<div id="avatar-3d-section">
	
	<!-- enrican 271221 <h3><?php esc_html_e( 'Avatar', 'avatar-3d-user-profile' ); ?></h3> -->
	
	<div id="avatar3d">
	
		<canvas id="avatarc"></canvas>
		
	    <div class="loading" id="js-loader">
	        <div class="loader"></div>
	    </div>
	    
	    <span class="drag-notice" id="js-drag-notice">Drag to rotate 360&#176;</span>	
	   
	    <div class="info3d">
			<div id="website" class="website" >
				<b><a href="https://avatar3Dcreator.com/">Avatar3DCreator</a></b><br>
				<?php if( $_GET['g3d'] ){ ?>
					<a href="<?php echo remove_query_arg( 'g3d',  $currenturl ); ?>">Are you female? Click here</a>
				<?php }else{ ?>
					<a href="<?php echo add_query_arg( 'g3d', '1', $currenturl ); ?>">Are you male? Click here</a>
				<?php } ?>
			</div>		
			<div id="gender" class="gender" >
				<!-- Are you female? <a href="<?php echo $currenturl; ?>?g=1">Click here</a> -->
			</div>
		</div>	    	    
	    	
	</div>

	<?php	
	// enrican 261221
		
	?>

		<table class="form-table">
			<tr class="upload-avatar-row">
				<th scope="row"><label for="avatar-3d"><?php esc_html_e( 'Upload Avatar', 'avatar-3d-user-profile' ); ?></label></th>
				<td style="width: 50px;" id="avatar-3d-photo">
			<?php
			add_filter( 'pre_option_avatar_rating', '__return_null' );     // ignore ratings here
			echo get_avatar_3d( $profileuser->ID );
			remove_filter( 'pre_option_avatar_rating', '__return_null' );
			?>
				</td>
				<td>
			<?php
			if ( ! $upload_rights = current_user_can( 'upload_files' ) ) {
				$upload_rights = empty( $this->options['caps'] );
			}

			if ( $upload_rights ) {
				do_action( 'avatar_3d_notices' );
				wp_nonce_field( 'avatar_3d_nonce', '_avatar_3d_nonce', false );
				$remove_url = add_query_arg(
					array(
						'action'   => 'remove-avatar-3d',
						'user_id'  => $profileuser->ID,
						'_wpnonce' => $this->remove_nonce,
					)
				);
				?>
				<?php
				
				// enrican per tutti scelgo da pc 261221
				/* if ( ! current_user_can( 'upload_files' ) ) { */
				// enrican
				
				?>
						<p style="display: inline-block; width: 26em;">
							<span class="description"><?php esc_html_e( 'Choose image from your pc and click bottom on Update:' ); ?></span><br />
							<input type="file" name="avatar-3d" id="avatar-3d" class="standard-text" />
							<span class="spinner" id="avatar-3d-spinner"></span>
						</p>
				<?php 
				
				// enrican per tutti scelgo da pc 261221
				/* } */ 
				// enrican
				
				?>
						<p>
						<?php if ( current_user_can( 'upload_files' ) && did_action( 'wp_enqueue_media' ) ) : ?>
							<a href="#" class="button hide-if-no-js" id="avatar-3d-media"><?php esc_html_e( 'Choose from Media Library', 'avatar-3d-user-profile' ); ?></a> &nbsp;
						<?php endif; ?>
							<a
								href="<?php echo esc_url( $remove_url ); ?>"
								class="button item-delete submitdelete deletion"
								id="avatar-3d-remove"
								<?php echo empty( $profileuser->avatar_3d ) ? ' style="display:none;"' : ''; ?>
							>
								<?php esc_html_e( 'Delete local avatar', 'avatar-3d-user-profile' ); ?>
							</a>
						</p>
				<?php
			} else {
				if ( empty( $profileuser->avatar_3d ) ) {
					echo '<span class="description">' . esc_html__( 'No local avatar is set. Set up your avatar at Gravatar.com.', 'avatar-3d-user-profile' ) . '</span>';
				} else {
					echo '<span class="description">' . esc_html__( 'You do not have media management permissions. To change your local avatar, contact the blog administrator.', 'avatar-3d-user-profile' ) . '</span>';
				}
			}
			?>
				</td>
			</tr>
			<tr class="ratings-row">
				<th scope="row"><?php esc_html_e( 'Rating' ); ?></th>
				<td colspan="2">
					<fieldset id="avatar-3d-ratings" <?php disabled( empty( $profileuser->avatar_3d ) ); ?>>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Rating' ); ?></span></legend>
					<?php
					if ( empty( $profileuser->avatar_3d_rating ) || ! array_key_exists( $profileuser->avatar_3d_rating, $this->avatar_ratings ) ) {
						$profileuser->avatar_3d_rating = 'G';
					}

					foreach ( $this->avatar_ratings as $key => $rating ) :
						echo "\n\t<label><input type='radio' name='avatar_3d_rating' value='" . esc_attr( $key ) . "' " . checked( $profileuser->avatar_3d_rating, $key, false ) . "/> $rating</label><br />";
					endforeach;
					?>
						<p class="description"><?php esc_html_e( 'If the local avatar is inappropriate for this site, Gravatar will be attempted.', 'avatar-3d-user-profile' ); ?></p>
					</fieldset></td>
			</tr>
		</table>
	</div>
	<?php
	}

	public function user_edit_form_tag() {
		echo 'enctype="multipart/form-data"';
	}

	public function assign_new_user_avatar( $url_or_media_id, $user_id ) {
		// delete old avatar
		$this->avatar_delete( $user_id );

		$meta_value = array();

		// set new avatar
		if ( is_int( $url_or_media_id ) ) {
			$meta_value['media_id'] = $url_or_media_id;
			$url_or_media_id        = wp_get_attachment_url( $url_or_media_id );
		}

		$meta_value['full'] = $url_or_media_id;

		update_user_meta( $user_id, 'avatar_3d', $meta_value );
	}

	public function edit_user_profile_update( $user_id ) {
		
		if ( empty( $_POST['_avatar_3d_nonce'] ) || ! wp_verify_nonce( $_POST['_avatar_3d_nonce'], 'avatar_3d_nonce' ) ) {
			return;
		}

		if ( ! empty( $_FILES['avatar-3d']['name'] ) ) :

			if ( false !== strpos( $_FILES['avatar-3d']['name'], '.php' ) ) {
				$this->avatar_upload_error = __( 'For security reasons, the extension ".php" cannot be in your file name.', 'avatar-3d-user-profile' );
				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );
				return;
			}

			if ( ! function_exists( 'media_handle_upload' ) ) {
				include_once ABSPATH . 'wp-admin/includes/media.php';
			}

			add_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			$this->user_id_being_edited = $user_id;
			$avatar_id                  = media_handle_upload(
				'avatar-3d',
				0,
				array(),
				array(
					'mimes'                    => array(
						'jpg|jpeg|jpe' => 'image/jpeg',
						'gif'          => 'image/gif',
						'png'          => 'image/png',
					),
					'test_form'                => false,
					'unique_filename_callback' => array( $this, 'unique_filename_callback' ),
				)
			);

			remove_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			if ( is_wp_error( $avatar_id ) ) {
				$this->avatar_upload_error = '<strong>' . __( 'There was an error uploading the avatar:', 'avatar-3d-user-profile' ) . '</strong> ' . esc_html( $avatar_id->get_error_message() );
				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );
				return;
			}

			$this->assign_new_user_avatar( $avatar_id, $user_id );

		endif;

		if ( isset( $avatar_id ) || $avatar = get_user_meta( $user_id, 'avatar_3d', true ) ) {
			if ( empty( $_POST['avatar_3d_rating'] ) || ! array_key_exists( $_POST['avatar_3d_rating'], $this->avatar_ratings ) ) {
				$_POST['avatar_3d_rating'] = key( $this->avatar_ratings );
			}

			update_user_meta( $user_id, 'avatar_3d_rating', $_POST['avatar_3d_rating'] );
		}
	}

	public function upload_size_limit( $bytes ) {
		return apply_filters( 'avatar_3d_user_profile_upload_limit', $bytes );
	}

	public function action_remove_avatar_3d() {
		if ( ! empty( $_GET['user_id'] ) && ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'remove_avatar_3d_nonce' ) ) {
			$user_id = (int) $_GET['user_id'];

			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this user.', 'avatar-3d-user-profile' ) );
			}

			$this->avatar_delete( $user_id );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				echo get_avatar_3d( $user_id );
			}
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die;
		}
	}

	public function ajax_assign_avatar_3d_media() {
		if ( empty( $_POST['user_id'] ) || empty( $_POST['media_id'] ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_user', $_POST['user_id'] ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'assign_avatar_3d_nonce' ) ) {
			die;
		}

		$media_id = (int) $_POST['media_id'];
		$user_id  = (int) $_POST['user_id'];

		if ( wp_attachment_is_image( $media_id ) ) {
			$this->assign_new_user_avatar( $media_id, $user_id );
		}

		echo get_avatar_3d( $user_id );

		die;
	}

	public function avatar_delete( $user_id ) {
		$old_avatars = (array) get_user_meta( $user_id, 'avatar_3d', true );

		if ( empty( $old_avatars ) ) {
			return;
		}

		if ( array_key_exists( 'media_id', $old_avatars ) ) {
			unset( $old_avatars['media_id'], $old_avatars['full'] );
		}

		if ( ! empty( $old_avatars ) ) {
			$upload_path = wp_upload_dir();

			foreach ( $old_avatars as $old_avatar ) {
				$old_avatar_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $old_avatar );
				if ( file_exists( $old_avatar_path ) ) {
					unlink( $old_avatar_path );
				}
			}
		}

		delete_user_meta( $user_id, 'avatar_3d' );
		delete_user_meta( $user_id, 'avatar_3d_rating' );
	}

	public function unique_filename_callback( $dir, $name, $ext ) {
		$user = get_user_by( 'id', (int) $this->user_id_being_edited );
		$name = $base_name = sanitize_file_name( $user->display_name . '_avatar_' . time() );

		$number = 1;
		while ( file_exists( $dir . "/$name$ext" ) ) {
			$name = $base_name . '_' . $number;
			$number++;
		}

		return $name . $ext;
	}

	public function user_profile_update_errors( WP_Error $errors ) {
		$errors->add( 'avatar_error', $this->avatar_upload_error );
	}

	public function register_rest_fields() {
		register_rest_field(
			'user',
			'avatar_3d',
			array(
				'get_callback'    => array( $this, 'get_avatar_rest' ),
				'update_callback' => array( $this, 'set_avatar_rest' ),
				'schema'          => array(
					'description' => 'The users avatar 3d user profile',
					'type'        => 'object',
				),
			)
		);
	}

	public function get_avatar_rest( $user ) {
		$local_avatar = get_user_meta( $user['id'], 'avatar_3d', true );
		if ( empty( $local_avatar ) ) {
			return;
		}
		return $local_avatar;
	}

	public function set_avatar_rest( $input, $user ) {
		$this->assign_new_user_avatar( $input['media_id'], $user->ID );
	}
}
