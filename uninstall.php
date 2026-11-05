<?php
/**
 * Uninstall: remove the Workflow Log and schema-version options only.
 *
 * Deactivation deletes nothing. Feedback meta (_pt_feedback) and staged copies
 * are retained deliberately: they are editorial content and history.
 *
 * @package PublicationTheory
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pt_workflow_log' );
delete_option( 'pt_db_version' );
