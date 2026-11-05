import apiFetch from '@wordpress/api-fetch';
import { Button, TextareaControl } from '@wordpress/components';
import { useDispatch, useSelect, select, resolveSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import FeedbackList from './feedback-list';

const STATUS_LABELS = {
	draft: __( 'Draft', 'publication-theory' ),
	'auto-draft': __( 'Draft', 'publication-theory' ),
	pending: __( 'Pending review', 'publication-theory' ),
	publish: __( 'Published', 'publication-theory' ),
	future: __( 'Scheduled', 'publication-theory' ),
	private: __( 'Private', 'publication-theory' ),
};

export default function Panel( { canPublish } ) {
	const { postId, postType, status, isDirty } = useSelect( ( sel ) => {
		const editor = sel( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			status: editor.getEditedPostAttribute( 'status' ),
			isDirty: editor.isEditedPostDirty(),
		};
	}, [] );
	const { savePost } = useDispatch( editorStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const { receiveEntityRecords } = useDispatch( coreStore );

	const [ entries, setEntries ] = useState( [] );
	const [ feedbackError, setFeedbackError ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const inFlight = useRef( false );

	const base = `/publication-theory/v1/posts/${ postId }`;

	const fetchFeedback = async () => {
		try {
			setEntries( await apiFetch( { path: `${ base }/feedback` } ) );
			setFeedbackError( false );
		} catch ( e ) {
			setFeedbackError( true );
		}
	};

	useEffect( () => {
		if ( ! postId ) {
			return undefined;
		}
		let ignore = false;
		apiFetch( { path: `/publication-theory/v1/posts/${ postId }/feedback` } )
			.then( ( result ) => {
				if ( ! ignore ) {
					setEntries( result );
					setFeedbackError( false );
				}
			} )
			.catch( () => {
				if ( ! ignore ) {
					setFeedbackError( true );
				}
			} );
		return () => {
			ignore = true;
		};
	}, [ postId ] );

	/**
	 * Refresh the editor's saved record so status (and the core Publish UI)
	 * reflects the server-side transition. We fetch the fresh record and push it
	 * into core-data with receiveEntityRecords, which the editor's selectors read.
	 */
	const refreshPost = async () => {
		const type = await resolveSelect( coreStore ).getPostType( postType );
		if ( ! type?.rest_base ) {
			throw new Error(
				__( 'Could not refresh the editor. Reload the page.', 'publication-theory' )
			);
		}
		const record = await apiFetch( {
			path: `/wp/v2/${ type.rest_base }/${ postId }?context=edit`,
		} );
		receiveEntityRecords( 'postType', postType, record );
	};

	const run = async ( action, data, successMessage ) => {
		if ( inFlight.current ) {
			return;
		}
		inFlight.current = true;
		setBusy( true );
		setError( '' );
		try {
			if ( action === 'submit' && ( isDirty || status === 'auto-draft' ) ) {
				await savePost();
				if ( select( editorStore ).didPostSaveRequestFail() ) {
					throw new Error(
						__( 'Saving failed, so the post was not submitted. Try again.', 'publication-theory' )
					);
				}
			}
			await apiFetch( { path: `${ base }/${ action }`, method: 'POST', data } );
			await Promise.all( [ refreshPost(), fetchFeedback() ] );
			setNote( '' );
			createSuccessNotice( successMessage, {
				type: 'snackbar',
				isDismissible: true,
			} );
		} catch ( e ) {
			setError(
				e?.message || __( 'Something went wrong. Please try again.', 'publication-theory' )
			);
		} finally {
			inFlight.current = false;
			setBusy( false );
		}
	};

	const latest = entries[ 0 ];

	return (
		<div className="pt-workflow">
			<p>
				<strong>{ __( 'Status:', 'publication-theory' ) }</strong>{ ' ' }
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
						disabled={ busy || ! [ 'draft', 'auto-draft' ].includes( status ) }
						accessibleWhenDisabled
						onClick={ () =>
							run(
								'submit',
								undefined,
								__( 'Submitted for review.', 'publication-theory' )
							)
						}
					>
						{ __( 'Submit for review', 'publication-theory' ) }
					</Button>
					{ latest && (
						<div style={ { marginTop: '16px' } }>
							<strong>{ __( 'Latest feedback', 'publication-theory' ) }</strong>
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
						label={ __( 'Feedback for the author (required)', 'publication-theory' ) }
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
								__( 'Sent back to the author.', 'publication-theory' )
							)
						}
					>
						{ __( 'Send back', 'publication-theory' ) }
					</Button>
				</>
			) }

			<div style={ { marginTop: '16px' } }>
				{ feedbackError ? (
					<p role="alert">
						{ __( 'Could not load feedback.', 'publication-theory' ) }
					</p>
				) : (
					<FeedbackList entries={ entries } />
				) }
			</div>
		</div>
	);
}
