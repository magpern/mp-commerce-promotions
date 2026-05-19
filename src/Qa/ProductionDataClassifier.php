<?php
/**
 * Classify Commerce Growth rows as test/demo/QA vs production-safe.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;
use MP\CommercePromotions\Service\BlockTestPages;

final class ProductionDataClassifier {

	/** @var list<string> */
	public const QA_RECIPIENT_EMAILS = array(
		'postmaster@biopentra.eu',
		'gift-card-smoke@example.com',
		'buyer@example.com',
	);

	/** @var list<int> */
	public const QA_CUSTOMER_IDS = array(
		999001,
	);

	/** @var list<int> */
	public const QA_ORDER_IDS = array(
		999002,
	);

	/** @var list<string> */
	public const QA_PRODUCT_SKUS = array(
		GiftCardQaProductSetup::PRODUCT_SKU,
		'mp-cp-block-qa-paid',
		'mp-cp-block-qa-gift',
	);

	public static function is_qa_recipient_email( ?string $email ): bool {
		if ( $email === null || trim( $email ) === '' ) {
			return false;
		}
		$email = strtolower( trim( $email ) );
		if ( in_array( $email, self::QA_RECIPIENT_EMAILS, true ) ) {
			return true;
		}
		foreach ( array( '@example.com', '@example.org', '@example.net' ) as $domain ) {
			if ( str_ends_with( $email, $domain ) ) {
				return true;
			}
		}
		foreach ( array( 'transfer-smoke', 'xfer-to-', 'partial@' ) as $needle ) {
			if ( str_contains( $email, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	public static function gift_card_label_is_test( ?string $label ): bool {
		if ( $label === null || trim( $label ) === '' ) {
			return false;
		}
		$label = strtolower( $label );

		return self::text_matches_test_patterns( $label );
	}

	public static function transaction_note_is_test( ?string $note ): bool {
		if ( $note === null || $note === '' ) {
			return false;
		}
		if ( str_starts_with( $note, QaDataTagger::QA_NOTE_PREFIX ) ) {
			return true;
		}
		$lower = strtolower( $note );
		if ( str_contains( $lower, 'transfer replacement for gift card' ) ) {
			return true;
		}

		return self::text_matches_test_patterns( $lower );
	}

	public static function promotion_name_is_test( string $name, ?string $internal_notes = null ): bool {
		$name = trim( $name );
		if ( $name === '' ) {
			return false;
		}
		if ( str_starts_with( $name, BlockTestPages::QA_PROMOTION_PREFIX ) ) {
			return true;
		}
		if ( $internal_notes !== null && str_contains( $internal_notes, QaDataTagger::QA_NOTE_PREFIX ) ) {
			return true;
		}

		$lower = strtolower( $name );
		$needles = array(
			'smoke',
			'wp-cli',
			'kill switch smoke',
			'batch trace smoke',
			'batch detail smoke',
			'coupon smoke',
			'code reversal smoke',
			'status smoke',
			'regression ',
			'simulation ',
		);
		foreach ( $needles as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return true;
			}
		}

		if ( preg_match( '/\bqa\b/i', $name ) === 1 ) {
			return true;
		}

		return false;
	}

	public static function promotion_campaign_label_is_test( ?string $label ): bool {
		if ( $label === null || trim( $label ) === '' ) {
			return false;
		}
		$upper = strtoupper( trim( $label ) );

		return in_array( $upper, array( 'SMOKE_CB', 'SMOKECAMPAIGN', 'SMOKE-LABEL' ), true )
			|| str_starts_with( strtoupper( $label ), 'SMOKE' );
	}

	public static function order_line_item_is_test( string $item_name ): bool {
		$lower = strtolower( $item_name );

		return str_contains( $lower, 'smoke' )
			|| str_contains( $lower, 'commerce growth gift card qa' )
			|| str_contains( $lower, 'mp cp blocks qa' );
	}

	public static function is_test_only_option( string $option_name ): bool {
		$test_options = array(
			'mp_cp_block_qa_gift_product_id',
			'mp_cp_block_qa_paid_product_id',
			'mp_cp_browser_qa_gift_product_id',
			'mp_cp_browser_qa_promotions',
			'mp_cp_gift_card_test_email_last',
		);

		return in_array( $option_name, $test_options, true );
	}

	public static function is_reports_transient_option( string $option_name ): bool {
		return str_starts_with( $option_name, '_transient_mp_cp_report' )
			|| str_starts_with( $option_name, '_transient_timeout_mp_cp_report' );
	}

	private static function text_matches_test_patterns( string $lower ): bool {
		foreach ( array( 'smoke', 'qa', 'test', 'demo', 'mp_cp', 'mp-cp', 'e2e' ) as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function __construct() {
	}
}
