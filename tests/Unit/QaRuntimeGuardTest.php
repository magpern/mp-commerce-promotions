<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Qa\QaDataTagger;
use MP\CommercePromotions\Qa\QaRuntimeGuard;
use PHPUnit\Framework\TestCase;

final class QaRuntimeGuardTest extends TestCase {

	protected function tearDown(): void {
		putenv( QaRuntimeGuard::ENV_ALLOW_LIVE_QA );
		putenv( QaRuntimeGuard::ENV_ALLOW_QA_EMAILS );
		putenv( QaRuntimeGuard::ENV_ALLOW_PERSISTENT_SETUP );
		parent::tearDown();
	}

	public function test_production_blocks_persistent_without_allow_flag(): void {
		$guard = new QaRuntimeGuard(
			'test-smoke',
			array( QaRuntimeGuard::CAP_PERSISTENT )
		);

		$this->expectException( \RuntimeException::class );
		$ref = new \ReflectionClass( $guard );
		$prop = $ref->getProperty( 'is_production_like' );
		$prop->setAccessible( true );
		$prop->setValue( $guard, true );

		$guard->assert_may_run();
	}

	public function test_production_allows_persistent_with_live_flag(): void {
		putenv( QaRuntimeGuard::ENV_ALLOW_LIVE_QA . '=1' );
		$guard = new QaRuntimeGuard(
			'test-smoke',
			array( QaRuntimeGuard::CAP_PERSISTENT )
		);

		$ref = new \ReflectionClass( $guard );
		$prop = $ref->getProperty( 'is_production_like' );
		$prop->setAccessible( true );
		$prop->setValue( $guard, true );

		$guard->assert_may_run();
		$this->assertFalse( $guard->is_dry_run() );
	}

	public function test_readonly_script_allowed_on_production(): void {
		$guard = new QaRuntimeGuard(
			'cheapest-item-smoke',
			array( QaRuntimeGuard::CAP_READONLY )
		);

		$ref = new \ReflectionClass( $guard );
		$prop = $ref->getProperty( 'is_production_like' );
		$prop->setAccessible( true );
		$prop->setValue( $guard, true );

		$guard->assert_may_run();
		$this->assertTrue( $guard->is_readonly_script() );
	}

	public function test_local_url_detected_as_non_production(): void {
		$this->assertTrue(
			QaRuntimeGuard::site_url_looks_non_production( 'http://localhost:8080/' )
		);
		$this->assertFalse(
			QaRuntimeGuard::is_production_like_environment( 'local', 'http://localhost:8080/' )
		);
	}

	public function test_qa_note_prefix_matches_run(): void {
		$note = QaDataTagger::qa_note( 'run-123', 'detail' );
		$this->assertTrue( QaDataTagger::note_matches_run( $note, 'run-123' ) );
		$this->assertFalse( QaDataTagger::note_matches_run( $note, 'other' ) );
	}
}
