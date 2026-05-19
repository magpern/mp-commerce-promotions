<?php
/**
 * Register and cleanup QA-tagged data created by smoke scripts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\PromotionService;

final class QaCleanupRegistry {

	private string $run_id;

	private string $script;

	private bool $enabled;

	/** @var list<int> */
	private array $product_ids = array();

	/** @var list<int> */
	private array $order_ids = array();

	/** @var list<int> */
	private array $promotion_ids = array();

	/** @var list<int> */
	private array $gift_card_ids = array();

	/** @var list<int> */
	private array $user_ids = array();

	public function __construct( string $script, bool $enabled = true, ?string $run_id = null ) {
		$this->script  = $script;
		$this->enabled = $enabled;
		$this->run_id  = $run_id ?? self::generate_run_id();
	}

	public function get_run_id(): string {
		return $this->run_id;
	}

	public function register_product( int $product_id ): void {
		if ( $product_id > 0 ) {
			QaDataTagger::tag_post( $product_id, $this->run_id, $this->script );
			$this->product_ids[] = $product_id;
		}
	}

	public function register_order( int $order_id ): void {
		if ( $order_id > 0 ) {
			QaDataTagger::tag_post( $order_id, $this->run_id, $this->script );
			$this->order_ids[] = $order_id;
		}
	}

	public function register_promotion( int $promotion_id ): void {
		if ( $promotion_id > 0 ) {
			$this->promotion_ids[] = $promotion_id;
		}
	}

	public function register_gift_card( int $gift_card_id ): void {
		if ( $gift_card_id > 0 ) {
			$this->gift_card_ids[] = $gift_card_id;
		}
	}

	public function register_user( int $user_id ): void {
		if ( $user_id > 0 ) {
			$this->user_ids[] = $user_id;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run_cleanup(): array {
		if ( ! $this->enabled ) {
			return array( 'skipped' => true, 'reason' => 'cleanup_disabled' );
		}

		$result = array(
			'run_id'             => $this->run_id,
			'products_removed'   => 0,
			'orders_removed'     => 0,
			'promotions_archived'=> 0,
			'gift_cards_voided'  => 0,
			'users_removed'      => 0,
			'errors'             => array(),
		);

		foreach ( array_unique( $this->product_ids ) as $product_id ) {
			if ( ! QaDataTagger::is_tagged_post( $product_id, $this->run_id ) ) {
				continue;
			}
			if ( $this->delete_post( $product_id ) ) {
				++$result['products_removed'];
			}
		}

		foreach ( array_unique( $this->order_ids ) as $order_id ) {
			if ( ! QaDataTagger::is_tagged_post( $order_id, $this->run_id ) ) {
				continue;
			}
			if ( $this->delete_post( $order_id, true ) ) {
				++$result['orders_removed'];
			}
		}

		foreach ( array_unique( $this->promotion_ids ) as $promotion_id ) {
			if ( $this->archive_promotion( $promotion_id ) ) {
				++$result['promotions_archived'];
			}
		}

		foreach ( array_unique( $this->gift_card_ids ) as $gift_card_id ) {
			if ( $this->void_gift_card( $gift_card_id ) ) {
				++$result['gift_cards_voided'];
			}
		}

		foreach ( array_unique( $this->user_ids ) as $user_id ) {
			if ( QaDataTagger::is_tagged_post( $user_id, $this->run_id ) && function_exists( 'wp_delete_user' ) ) {
				if ( wp_delete_user( $user_id ) ) {
					++$result['users_removed'];
				}
			}
		}

		return $result;
	}

	private function delete_post( int $post_id, bool $force = false ): bool {
		if ( ! function_exists( 'wp_delete_post' ) ) {
			return false;
		}

		$deleted = wp_delete_post( $post_id, $force );

		return $deleted !== false && $deleted !== null;
	}

	private function archive_promotion( int $promotion_id ): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		try {
			$repo    = new PromotionRepository( $wpdb );
			$promo   = $repo->find( $promotion_id );
			if ( $promo === null ) {
				return false;
			}
			$status = $promo->get_status();
			if ( $status === PromotionStatus::ARCHIVED ) {
				return true;
			}
			$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
			$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
			$logger  = new \MP\CommercePromotions\Service\AuditLogger( $audit );
			$service = new PromotionService( $repo, $factory, $logger );
			$service->change_status( $promo, PromotionStatus::ARCHIVED );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function void_gift_card( int $gift_card_id ): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		try {
			$ledger = new GiftCardLedger(
				new GiftCardRepository( $wpdb ),
				new GiftCardTransactionRepository( $wpdb )
			);
			$ledger->void_card( $gift_card_id, QaDataTagger::qa_note( $this->run_id, 'qa cleanup' ) );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private static function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return 'qa-' . bin2hex( random_bytes( 8 ) );
	}
}
