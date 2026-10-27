<?php
/**
 * Uninstall: remove the Workflow Log option only.
 *
 * Deactivation deletes nothing. Feedback meta (_spw_feedback) and staged copies
 * are retained deliberately: they are editorial content and history.
 *
 * @package SPW
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'spw_workflow_log' );
