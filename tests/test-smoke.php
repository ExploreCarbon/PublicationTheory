<?php
class SPW_Smoke_Test extends WP_UnitTestCase {
	public function test_plugin_boots() {
		$this->assertTrue( class_exists( 'SPW\\Plugin' ) );
		$this->assertTrue( defined( 'SPW_VERSION' ) );
	}
}
