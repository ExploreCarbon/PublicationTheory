import { dispatch, select } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore } from '@wordpress/editor';

const NOTICE_ID = 'spw-presence';
let lastNames = '';

function names( users ) {
	return users.map( ( u ) => u.name ).join( ', ' );
}

/**
 * Show or clear the "also editing" notice. The notice renders React text
 * (never HTML) with role="status" semantics from core's notices; the warning
 * status plus the explicit words keep it from being colour-only.
 *
 * @param {Array} users Other users currently holding the edit lock.
 */
export function renderPresence( users ) {
	const { createNotice, removeNotice } = dispatch( noticesStore );
	if ( ! users.length ) {
		if ( lastNames ) {
			removeNotice( NOTICE_ID );
			lastNames = '';
		}
		return;
	}
	const current = names( users );
	if ( current === lastNames ) {
		return;
	}
	lastNames = current;
	const message =
		users.length === 1
			? sprintf(
					/* translators: %s: user display name. */
					__( '%s is also editing this item.', 'spw' ),
					current
			  )
			: sprintf(
					/* translators: %s: comma separated user names. */
					__( '%s are also editing this item.', 'spw' ),
					current
			  );
	createNotice( 'warning', message, {
		id: NOTICE_ID,
		isDismissible: true,
		// Core's notices store announces the message politely (speak: true).
		speak: true,
	} );
}

/**
 * Hook into core's Heartbeat: send the current post id, read other editors back.
 */
export default function initPresence() {
	const $ = window.jQuery;
	if ( ! $ ) {
		return;
	}
	$( document ).on( 'heartbeat-send.spw-presence', ( event, data ) => {
		const postId = select( editorStore ).getCurrentPostId();
		if ( postId ) {
			data.spw_presence = { post_id: postId };
		}
	} );
	$( document ).on( 'heartbeat-tick.spw-presence', ( event, data ) => {
		if ( data && data.spw_presence ) {
			renderPresence( data.spw_presence.users || [] );
		}
	} );
}
