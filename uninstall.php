<?php
/**
 * Runs on plugin uninstall.
 *
 * @package JIE_Link_Checker
 */

// Only execute when WordPress calls this file during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO: Remove plugin options and custom database tables when added.
