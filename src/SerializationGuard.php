<?php declare(strict_types=1);

/**
 * @package     Localzet Tunnel
 * @link        https://github.com/localzet/Tunnel
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Tunnel;

/** @internal */
final class SerializationGuard
{
    public static function supports(mixed $value): bool
    {
        $pending = [['value' => $value, 'reference' => null, 'exit' => false]];
        $activeReferences = [];

        while ($pending !== []) {
            $item = array_pop($pending);

            if ($item['exit']) {
                unset($activeReferences[$item['reference']]);
                continue;
            }

            $reference = $item['reference'];
            if ($reference !== null) {
                if (isset($activeReferences[$reference])) {
                    return false;
                }

                $activeReferences[$reference] = true;
                $pending[] = ['value' => null, 'reference' => $reference, 'exit' => true];
            }

            $current = $item['value'];
            if (is_object($current)) {
                return false;
            }
            if (!is_array($current)) {
                if (!is_scalar($current) && $current !== null) {
                    return false;
                }
                continue;
            }

            foreach ($current as $key => $child) {
                $elementReference = \ReflectionReference::fromArrayElement($current, $key);
                $pending[] = [
                    'value' => $child,
                    'reference' => $elementReference === null ? null : bin2hex($elementReference->getId()),
                    'exit' => false,
                ];
            }
        }

        return true;
    }
}
