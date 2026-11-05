import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import Panel from './panel';
import initPresence from './presence';

initPresence();

const config = window.ptWorkflow || {};

registerPlugin( 'pt-workflow', {
	render: () => (
		<PluginDocumentSettingPanel
			name="pt-workflow"
			title={ __( 'Review workflow', 'publication-theory' ) }
			className="pt-workflow-panel"
		>
			<Panel canPublish={ !! config.canPublish } />
		</PluginDocumentSettingPanel>
	),
} );
