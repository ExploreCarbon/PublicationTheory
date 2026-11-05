<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class PT_Smoke_Test extends WP_UnitTestCase {
	public function test_plugin_boots() {
		$this->assertTrue( class_exists( 'PublicationTheory\\Plugin' ) );
		$this->assertTrue( defined( 'PT_VERSION' ) );
	}
}
