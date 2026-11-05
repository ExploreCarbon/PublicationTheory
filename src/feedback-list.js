import { __ } from '@wordpress/i18n';

/**
 * Feedback history. Notes are rendered as text only (React escapes).
 */
export default function FeedbackList( { entries, headingLevel = 3 } ) {
	const Heading = `h${ headingLevel }`;
	if ( ! entries.length ) {
		return (
			<p className="pt-feedback-empty">
				{ __( 'No feedback yet.', 'publication-theory' ) }
			</p>
		);
	}
	return (
		<section
			className="pt-feedback"
			aria-labelledby="pt-feedback-heading"
		>
			<Heading
				id="pt-feedback-heading"
				className="pt-feedback-heading"
			>
				{ __( 'Feedback history', 'publication-theory' ) }
			</Heading>
			<ul style={ { margin: 0, paddingLeft: '1.2em' } }>
				{ entries.map( ( entry ) => (
					<li key={ entry.id } style={ { marginBottom: '8px' } }>
						<strong>
							{ entry.editor_name || __( 'An editor', 'publication-theory' ) }
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
