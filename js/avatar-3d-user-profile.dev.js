var avatar_3d_frame, avatar_spinner, avatar_ratings, avatar_container, avatar_form_button;
var avatar_working = false;

jQuery(document).ready(function($){
	$( document.getElementById('avatar-3d-media') ).on( 'click', function(event) {
		event.preventDefault();

		if ( avatar_working )
			return;

		if ( avatar_3d_frame ) {
			avatar_3d_frame.open();
			return;
		}

		avatar_3d_frame = wp.media.frames.avatar_3d_frame = wp.media({
			title: i10n_Avatar3dUserProfile.insertMediaTitle,
			button: { text: i10n_Avatar3dUserProfile.insertIntoPost },
			library : { type : 'image'},
			multiple: false
		});

		avatar_3d_frame.on( 'select', function() {
			// We set multiple to false so only get one image from the uploader
			avatar_lock('lock');
			var avatar_url = avatar_3d_frame.state().get('selection').first().toJSON().id;
			jQuery.post( ajaxurl, { action: 'assign_avatar_3d_media', media_id: avatar_url, user_id: i10n_Avatar3dUserProfile.user_id, _wpnonce: i10n_Avatar3dUserProfile.mediaNonce }, function(data) {
				if ( data != '' ) {
					avatar_container.innerHTML = data;
					$( document.getElementById('avatar-3d-remove') ).show();
					avatar_ratings.disabled = false;
					avatar_lock('unlock');
				}
			});
		});

		avatar_3d_frame.open();
	});

	$( document.getElementById('avatar-3d-remove') ).on('click',function(event){
		event.preventDefault();

		if ( avatar_working )
			return;

		avatar_lock('lock');
		$.get( ajaxurl, { action: 'remove_avatar_3d', user_id: i10n_Avatar3dUserProfile.user_id, _wpnonce: i10n_Avatar3dUserProfile.deleteNonce })
		.done(function(data) {
			if ( data != '' ) {
				avatar_container.innerHTML = data;
				$( document.getElementById('avatar-3d-remove') ).hide();
				avatar_ratings.disabled = true;
				avatar_lock('unlock');
			}
		});
	});
});

function avatar_lock( lock_or_unlock ) {
	if ( undefined == avatar_spinner ) {
		avatar_ratings = document.getElementById('avatar-3d-ratings');
		avatar_spinner = jQuery( document.getElementById('avatar-3d-spinner') );
		avatar_container = document.getElementById('avatar-3d-photo');
		avatar_form_button = jQuery(avatar_ratings).closest('form').find('input[type=submit]');
	}

	if ( lock_or_unlock == 'unlock' ) {
		avatar_working = false;
		avatar_form_button.removeAttr('disabled');
		avatar_spinner.hide();
	} else {
		avatar_working = true;
		avatar_form_button.attr('disabled','disabled');
		avatar_spinner.show();
	}
}