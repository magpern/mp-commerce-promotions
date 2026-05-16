<?php
/**
 * Reusable WordPress admin section/card markup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminSection {

	/**
	 * Render a titled admin card section.
	 *
	 * @param string               $title       Section title.
	 * @param callable             $content     Callback that prints section body markup.
	 * @param string|null          $description Optional description below the title.
	 * @param array<string, mixed> $args        Optional: heading (h2|h3), width (narrow|wide), class.
	 */
	public static function render( string $title, callable $content, ?string $description = null, array $args = array() ): void {
		$heading = $args['heading'] ?? 'h2';
		if ( $heading !== 'h3' ) {
			$heading = 'h2';
		}

		$width = $args['width'] ?? ( $heading === 'h2' ? 'narrow' : 'wide' );
		$style = $width === 'wide'
			? 'max-width:100%;padding:12px 16px;margin:8px 0 16px;'
			: 'max-width:720px;padding:12px 16px;margin:16px 0;';

		$card_class = 'card';
		if ( isset( $args['class'] ) && $args['class'] !== '' ) {
			$card_class .= ' ' . $args['class'];
		}

		echo '<div class="' . esc_attr( $card_class ) . '" style="' . esc_attr( $style ) . '">';
		printf(
			'<%1$s style="margin-top:0;">%2$s</%1$s>',
			$heading,
			esc_html( $title )
		);

		if ( $description !== null && $description !== '' ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		$content();

		echo '</div>';
	}
}
