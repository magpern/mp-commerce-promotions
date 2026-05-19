<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Qa\QaEmailSuppression;
use MP\CommercePromotions\Qa\QaRuntimeGuard;
use PHPUnit\Framework\TestCase;

final class QaEmailSuppressionTest extends TestCase {

	public function test_emails_disabled_by_default_in_smoke_context(): void {
		$guard = new QaRuntimeGuard(
			'gift-card-mail-smoke',
			array( QaRuntimeGuard::CAP_PERSISTENT, QaRuntimeGuard::CAP_EMAIL )
		);

		$this->assertTrue( $guard->requires_email() );
		$this->assertFalse( $guard->allows_email() );
	}

	public function test_pre_wp_mail_short_circuits_when_active(): void {
		QaEmailSuppression::reset_log();
		QaEmailSuppression::enable();
		QaEmailSuppression::enable();

		$result = QaEmailSuppression::pre_wp_mail(
			null,
			array(
				'to'      => 'qa@example.org',
				'subject' => 'test',
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, QaEmailSuppression::suppressed_count() );

		QaEmailSuppression::disable();
	}
}
