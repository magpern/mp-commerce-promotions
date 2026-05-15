<?php
/**
 * Future rule action contract (no cart effects yet).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

interface ActionInterface {

	/**
	 * Machine-readable action type (e.g. free_product).
	 */
	public function get_type(): string;
}
