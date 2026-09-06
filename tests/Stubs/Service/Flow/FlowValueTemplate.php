<?php

/**
 * Test stub for OpenRegister's FlowValueTemplate.
 *
 * Mirrors the real class's rendering contract closely enough for dossiq's
 * node tests to be meaningful: `{{ dotted.path }}` placeholders resolve
 * against the item's record, a value that is EXACTLY one placeholder keeps
 * the resolved value's type, and `renderTracked()` reports the placeholder
 * paths that resolved to nothing. Self-skips when the real class is present
 * (openregister installed), so the genuine implementation always wins.
 *
 * ⚠️ This is a second implementation of behaviour OpenRegister owns, kept
 * DELIBERATELY MINIMAL: only what dossiq's assignee rendering exercises.
 * When the real template grows semantics dossiq starts relying on, extend
 * this stub in the same change or the unit suite goes green over behaviour
 * the runtime does not have.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowValueTemplate', false) === false) {
    /**
     * Stub of OpenRegister's FlowValueTemplate for unit tests.
     */
    final class FlowValueTemplate {

        /**
         * The placeholder shape: `{{ dotted.path }}`.
         *
         * @var string
         */
        private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/';

        /**
         * The same shape, anchored — a value that is ONLY a placeholder.
         *
         * @var string
         */
        private const WHOLE = '/^\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}$/';


        /**
         * Render a value and report the placeholders that came out empty.
         *
         * @param mixed $value The authored value.
         * @param array $json  The item's record.
         *
         * @return array{value: mixed, unresolved: array<int, string>} The rendered value and empty placeholder paths.
         */
        public static function renderTracked(mixed $value, array $json): array {
            $unresolved = [];

            if (is_string($value) === false) {
                return [
                    'value'      => $value,
                    'unresolved' => [],
                ];
            }

            $whole = [];
            if (preg_match(self::WHOLE, $value, $whole) === 1) {
                $resolved = self::valueAt(path: $whole[1], json: $json);
                if ($resolved === null || $resolved === '') {
                    $unresolved[] = $whole[1];
                }

                return [
                    'value'      => $resolved,
                    'unresolved' => $unresolved,
                ];
            }

            $rendered = (string) preg_replace_callback(
                self::PLACEHOLDER,
                static function (array $matches) use ($json, &$unresolved): string {
                    $resolved = self::valueAt(path: $matches[1], json: $json);
                    if ($resolved === null || $resolved === '') {
                        $unresolved[] = $matches[1];
                    }

                    if (is_array($resolved) === true) {
                        return (string) json_encode($resolved);
                    }

                    return (string) $resolved;
                },
                $value
            );

            return [
                'value'      => $rendered,
                'unresolved' => array_values(array_unique($unresolved)),
            ];

        }//end renderTracked()


        /**
         * Render a value against an item's record.
         *
         * @param mixed $value The authored value.
         * @param array $json  The item's record.
         *
         * @return mixed The rendered value.
         */
        public static function render(mixed $value, array $json): mixed {
            return self::renderTracked(value: $value, json: $json)['value'];

        }//end render()


        /**
         * The value at a dotted path, or null when the path is absent.
         *
         * @param string $path The dotted path.
         * @param array  $json The item's record.
         *
         * @return mixed The value, or null.
         */
        private static function valueAt(string $path, array $json): mixed {
            $cursor = $json;
            foreach (explode('.', $path) as $segment) {
                if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                    return null;
                }

                $cursor = $cursor[$segment];
            }

            return $cursor;

        }//end valueAt()
    }//end class
}//end if
