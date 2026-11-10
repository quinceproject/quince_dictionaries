<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\ApiPlatform\Exception;

/** Słownika o tym kluczu sklep nie zna. Najpierw trzeba wysłać jego definicję. */
final class DictionaryNotFoundException extends \RuntimeException
{
}
