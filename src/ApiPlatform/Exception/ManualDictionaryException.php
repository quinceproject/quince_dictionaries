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

/** Słownik prowadzi sklep ręcznie, więc przesyłka przez API go nie nadpisuje. */
final class ManualDictionaryException extends \RuntimeException
{
}
