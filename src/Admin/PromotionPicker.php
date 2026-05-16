<?php
/**
 * Reusable promotion select dropdown for admin forms (no AJAX).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;

final class PromotionPicker {

	private PromotionRepository $promotions;

	public function __construct( PromotionRepository $promotions ) {
		$this->promotions = $promotions;
	}

	/**
	 * @param array{
	 *     name?: string,
	 *     id?: string,
	 *     selected?: int|null,
	 *     include_empty?: bool,
	 *     empty_label?: string,
	 *     exclude_ids?: list<int>,
	 *     limit?: int
	 * } $args
	 */
	public function render_select( array $args = array() ): void {
		$name          = isset( $args['name'] ) ? (string) $args['name'] : 'promotion_id';
		$id            = isset( $args['id'] ) ? (string) $args['id'] : 'mp_cp_promotion_picker';
		$selected      = isset( $args['selected'] ) ? $args['selected'] : null;
		$include_empty = ! isset( $args['include_empty'] ) || (bool) $args['include_empty'];
		$empty_label   = isset( $args['empty_label'] )
			? (string) $args['empty_label']
			: __( 'All promotions', 'mp-commerce-promotions' );
		$exclude       = isset( $args['exclude_ids'] ) && is_array( $args['exclude_ids'] )
			? array_map( 'intval', $args['exclude_ids'] )
			: array();
		$limit         = isset( $args['limit'] ) ? (int) $args['limit'] : 100;

		$options = $this->load_promotions( $limit, $exclude );

		printf(
			'<select id="%1$s" name="%2$s">',
			esc_attr( $id ),
			esc_attr( $name )
		);

		if ( $include_empty ) {
			printf(
				'<option value="">%s</option>',
				esc_html( $empty_label )
			);
		}

		foreach ( $options as $promotion ) {
			$pid = $promotion->get_id();
			if ( $pid === null || $pid <= 0 ) {
				continue;
			}

			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $pid,
				selected( $selected, $pid, false ),
				esc_html( $this->format_option_label( $promotion ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Checkbox table for excluded promotion IDs (latest promotions, no JS).
	 *
	 * @param list<int> $selected_ids Currently excluded promotion IDs.
	 * @param int       $current_promotion_id Promotion being edited (excluded from list).
	 */
	public function render_exclusion_checklist( array $selected_ids, int $current_promotion_id ): void {
		$selected_map = array();
		foreach ( $selected_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$selected_map[ $id ] = true;
			}
		}

		$exclude = $current_promotion_id > 0 ? array( $current_promotion_id ) : array();
		$rows    = $this->load_promotions( 25, $exclude );

		if ( $rows === array() ) {
			echo '<p class="description">' . esc_html__( 'No other promotions available to exclude.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<fieldset style="margin:8px 0;max-width:640px;">';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Exclude promotions', 'mp-commerce-promotions' ) . '</legend>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col" style="width:2em;">' . esc_html__( 'Exclude', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $promotion ) {
			$pid = $promotion->get_id();
			if ( $pid === null || $pid <= 0 ) {
				continue;
			}

			$checked = isset( $selected_map[ $pid ] );
			echo '<tr>';
			echo '<td><input type="checkbox" name="promotion_excluded_check[]" value="' . esc_attr( (string) $pid ) . '"';
			checked( $checked, true, false );
			echo ' /></td>';
			echo '<td>' . esc_html( (string) $pid ) . '</td>';
			echo '<td>' . esc_html( $promotion->get_name() ) . '</td>';
			echo '<td>' . esc_html( $promotion->get_status() ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';
	}

	/**
	 * @param list<int> $exclude_ids
	 * @return list<Promotion>
	 */
	private function load_promotions( int $limit, array $exclude_ids ): array {
		$limit = max( 1, min( 100, $limit ) );

		try {
			$list = $this->promotions->find_filtered(
				array(
					'limit' => $limit,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}

		if ( $exclude_ids === array() ) {
			return $list;
		}

		$exclude_map = array_flip( $exclude_ids );
		$out         = array();

		foreach ( $list as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			$pid = $promotion->get_id();
			if ( $pid !== null && isset( $exclude_map[ $pid ] ) ) {
				continue;
			}
			$out[] = $promotion;
		}

		return $out;
	}

	private function format_option_label( Promotion $promotion ): string {
		$pid = $promotion->get_id();

		return sprintf(
			'#%d — %s (%s)',
			$pid ?? 0,
			$promotion->get_name(),
			$promotion->get_status()
		);
	}
}
