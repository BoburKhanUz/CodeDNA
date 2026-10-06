<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use InvalidArgumentException;
use stdClass;

/**
 * Validates decoded JSON (json_decode(..., associative: false)) against the
 * analyzer's published JSON Schemas (packages/api-contracts/analyzer/v1).
 *
 * Supports exactly the keyword subset those schemas use, the same subset as
 * the analyzer's own test validator: type, required, properties,
 * additionalProperties (false or a schema), items, enum, const, pattern,
 * minLength/maxLength, minimum/maximum, minItems, uniqueItems and local
 * $ref. An unknown keyword is a programming error and throws, so a schema
 * change can never be silently half-checked.
 */
final class JsonSchemaValidator
{
    private const SUPPORTED = [
        '$schema', '$id', '$defs', '$ref', 'title', 'type', 'required', 'properties', 'additionalProperties',
        'items', 'enum', 'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'uniqueItems',
    ];

    /**
     * @param  array<string, mixed>  $schema  decoded with associative: true
     * @return list<string> violations as JSON paths (never the offending values)
     */
    public function validate(mixed $instance, array $schema): array
    {
        return $this->check($instance, $schema, $schema, '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private function check(mixed $instance, array $schema, array $root, string $path): array
    {
        $unknown = array_diff(array_keys($schema), self::SUPPORTED);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unsupported JSON Schema keywords at '.$path.': '.implode(', ', $unknown));
        }

        if (isset($schema['$ref'])) {
            $target = $root;
            foreach (explode('/', ltrim((string) $schema['$ref'], '#/')) as $part) {
                $target = $target[$part] ?? throw new InvalidArgumentException('Unresolvable $ref '.$schema['$ref']);
            }

            return $this->check($instance, $target, $root, $path);
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matches = false;
            foreach ($types as $type) {
                $matches = $matches || $this->isType($instance, (string) $type);
            }
            if (! $matches) {
                return ["{$path}: wrong type"];
            }
        }

        $errors = [];
        if (array_key_exists('const', $schema) && ! $this->equal($instance, $schema['const'])) {
            $errors[] = "{$path}: not the expected constant";
        }
        if (array_key_exists('enum', $schema)) {
            $found = false;
            foreach ((array) $schema['enum'] as $option) {
                $found = $found || $this->equal($instance, $option);
            }
            if (! $found) {
                $errors[] = "{$path}: not an allowed value";
            }
        }

        if (is_string($instance)) {
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/u', $instance) !== 1) {
                $errors[] = "{$path}: does not match the pattern";
            }
            $length = mb_strlen($instance);
            if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                $errors[] = "{$path}: length out of bounds";
            }
        }

        if ((is_int($instance) || is_float($instance))
            && (($instance < ($schema['minimum'] ?? -PHP_INT_MAX)) || ($instance > ($schema['maximum'] ?? PHP_INT_MAX)))) {
            $errors[] = "{$path}: out of bounds";
        }

        if (is_array($instance)) {
            if (count($instance) < ($schema['minItems'] ?? 0)) {
                $errors[] = "{$path}: too few items";
            }
            if (($schema['uniqueItems'] ?? false) === true) {
                $encoded = array_map(CanonicalJson::encode(...), $instance);
                if (count(array_unique($encoded)) !== count($encoded)) {
                    $errors[] = "{$path}: items not unique";
                }
            }
            if (isset($schema['items'])) {
                foreach ($instance as $index => $item) {
                    $errors = [...$errors, ...$this->check($item, (array) $schema['items'], $root, "{$path}[{$index}]")];
                }
            }
        }

        if ($instance instanceof stdClass) {
            $members = get_object_vars($instance);
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (! array_key_exists((string) $name, $members)) {
                    $errors[] = "{$path}: missing {$name}";
                }
            }
            $properties = (array) ($schema['properties'] ?? []);
            $additional = $schema['additionalProperties'] ?? true;
            foreach ($members as $name => $value) {
                $name = (string) $name;
                if (array_key_exists($name, $properties)) {
                    $errors = [...$errors, ...$this->check($value, (array) $properties[$name], $root, "{$path}.{$name}")];
                } elseif ($additional === false) {
                    $errors[] = "{$path}: unexpected property";
                } elseif (is_array($additional)) {
                    $errors = [...$errors, ...$this->check($value, $additional, $root, "{$path}.{$name}")];
                }
            }
        }

        return $errors;
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => throw new InvalidArgumentException("Unknown JSON Schema type {$type}"),
        };
    }

    private function equal(mixed $instance, mixed $expected): bool
    {
        // Schema constants are scalars or null in the analyzer contracts.
        if (is_array($expected) || $instance instanceof stdClass || is_array($instance)) {
            return false;
        }
        if ((is_int($instance) || is_float($instance)) && (is_int($expected) || is_float($expected))) {
            return $instance == $expected;
        }

        return $instance === $expected;
    }
}
