import { __ } from '@wordpress/i18n';

/**
 * Feedback history. Notes are rendered as text only (React escapes).
 */
export default function FeedbackList( { entries, headingLevel = 3 } ) {
	const Heading = `h${ headingLevel }`;
	if ( ! entries.length ) {
		return (
			<p className="spw-feedback-empty">
				{ __( 'No feedback yet.', 'spw' ) }
			</p>
		);
	}
	return (
		<section className="spw-feedback">
			<Heading className="spw-feedback-heading">
				{ __( 'Feedback history', 'spw' ) }
			</Heading>
			<ul style={ { margin: 0, paddingLeft: '1.2em' } }>
				{ entries.map( ( entry ) => (
					<li key={ entry.id } style={ { marginBottom: '8px' } }>
						<strong>
							{ entry.editor_name || __( 'An editor', 'spw' ) }
						</strong>
						{ entry.time && (
							<>
								{ ' · ' }
								<time dateTime={ entry.time }>
									{ new Date( entry.time ).toLocaleString() }
								</time>
							</>
						) }
						<div style={ { whiteSpace: 'pre-wrap' } }>
							{ entry.note }
						</div>
					</li>
				) ) }
			</ul>
		</section>
	);
}
