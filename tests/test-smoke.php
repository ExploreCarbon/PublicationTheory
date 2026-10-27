<?php
class SPW_Smoke_Test extends WP_UnitTestCase {
	public function test_plugin_boots() {
		$this->assertTrue( class_exists( 'SPW' . chr( 92 ) . 'Plugin' ) );
		$this->assertTrue( defined( 'SPW_VERSION' ) );
	}
}
