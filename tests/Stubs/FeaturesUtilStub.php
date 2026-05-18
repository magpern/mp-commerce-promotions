<?php
/**
 * PHPUnit stub for WooCommerce FeaturesUtil.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Stubs;

final class FeaturesUtilStub {

	/**
	 * @param string $feature Feature slug.
	 * @param string $plugin_file Plugin main file path.
	 * @param bool   $positive Whether compatible.
	 */
	public static function declare_compatibility( string $feature, string $plugin_file, bool $positive ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $feature, $plugin_file, $positive );
	}
}
