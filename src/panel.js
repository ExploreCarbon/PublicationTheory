import apiFetch from '@wordpress/api-fetch';
import { Button, TextareaControl } from '@wordpress/components';
import { useDispatch, useSelect, select as dataSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { store as noticesStore } from '@wordpress/notices';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import FeedbackList from './feedback-list';

const STATUS_LABELS = {
	draft: __( 'Draft', 'spw' ),
	pending: __( 'Pending review', 'spw' ),
	publish: __( 'Published', 'spw' ),
	future: __( 'Scheduled', 'spw' ),
	private: __( 'Private', 'spw' ),
};

export default function Panel( { canPublish } ) {
	const { postId, postType, status, isDirty } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			status: editor.getEditedPostAttribute( 'status' ),
			isDirty: editor.isEditedPostDirty(),
		};
	}, [] );
	const { savePost } = useDispatch( editorStore );
	const { createErrorNotice, createSuccessNotice, removeNotice } =
		useDispatch( noticesStore );
	const { receiveEntityRecords } = useDispatch( coreStore );

	const [ entries, setEntries ] = useState( [] );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const base = `/spw/v1/posts/${ postId }`;

	const loadFeedback = useCallback( async () => {
		try {
			setEntries( await apiFetch( { path: `${ base }/feedback` } ) );
		} catch ( e ) {
			setEntries( [] );
		}
	}, [ base ] );

	useEffect( () => {
		if ( postId ) {
			loadFeedback();
		}
	}, [ postId, loadFeedback ] );

	/**
	 * Refresh the editor's saved record so status (and the core Publish UI)
	 * reflects the server-side transition. We fetch the fresh record and push it
	 * into core-data with receiveEntityRecords, which the editor's selectors read.
	 */
	const refreshPost = async () => {
		const type = dataSelect( coreStore ).getPostType( postType );
		if ( ! type?.rest_base ) {
			return;
		}
		const record = await apiFetch( {
			path: `/wp/v2/${ type.rest_base }/${ postId }?context=edit`,
		} );
		receiveEntityRecords( 'postType', postType, record );
	};

	const run = async ( action, data, successMessage ) => {
		setBusy( true );
		setError( '' );
		removeNotice( 'spw-error' );
		try {
			if ( action === 'submit' && isDirty ) {
				await savePost();
			}
			await apiFetch( { path: `${ base }/${ action }`, method: 'POST', data } );
			await Promise.all( [ refreshPost(), loadFeedback() ] );
			setNote( '' );
			createSuccessNotice( successMessage, {
				type: 'snackbar',
				isDismissible: true,
			} );
			speak( successMessage, 'polite' );
		} catch ( e ) {
			const message =
				e?.message || __( 'Something went wrong. Please try again.', 'spw' );
			setError( message );
			createErrorNotice( message, {
				id: 'spw-error',
				type: 'snackbar',
				spokenMessage: null,
			} );
			speak( message, 'assertive' );
		} finally {
			setBusy( false );
		}
	};

	const latest = entries[ 0 ];

	return (
		<div className="spw-workflow">
			<p>
				<strong>{ __( 'Status:', 'spw' ) }</strong>{ ' ' }
				{ STATUS_LABELS[ status ] || status }
			</p>

			{ error && (
				<div
					role="alert"
					className="components-notice is-error"
					style={ { margin: '0 0 12px', padding: '8px 12px' } }
				>
					{ error }
				</div>
			) }

			{ ! canPublish && (
				<>
					<Button
						variant="primary"
						disabled={ busy || status !== 'draft' }
						accessibleWhenDisabled
						onClick={ () =>
							run(
								'submit',
								undefined,
								__( 'Submitted for review.', 'spw' )
							)
						}
					>
						{ __( 'Submit for review', 'spw' ) }
					</Button>
					{ latest && (
						<div style={ { marginTop: '16px' } }>
							<strong>{ __( 'Latest feedback', 'spw' ) }</strong>
							<div style={ { whiteSpace: 'pre-wrap' } }>
								{ latest.note }
							</div>
						</div>
					) }
				</>
			) }

			{ canPublish && (
				<>
					<TextareaControl
						label={ __( 'Feedback for the author (required)', 'spw' ) }
						value={ note }
						onChange={ setNote }
						rows={ 4 }
						disabled={ busy }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						disabled={ busy || ! note.trim() || status !== 'pending' }
						accessibleWhenDisabled
						onClick={ () =>
							run(
								'send-back',
								{ note: note.trim() },
								__( 'Sent back to the author.', 'spw' )
							)
						}
					>
						{ __( 'Send back', 'spw' ) }
					</Button>
				</>
			) }

			<div style={ { marginTop: '16px' } }>
				<FeedbackList entries={ entries } />
			</div>
		</div>
	);
}
