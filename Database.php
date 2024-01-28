<?php

namespace FpDbTest;

use Exception;
use mysqli;

/**
 * Represents a database handler that processes SQL queries with placeholders.
 */
class Database implements DatabaseInterface
{
	private mysqli $mysqli;
	private const SKIP_VALUE = 'SKIP_BLOCK'; // Special value for conditional blocks.

	/**
	 * Constructs a Database instance with a mysqli object.
	 *
	 * @param mysqli $mysqli The mysqli object for database operations.
	 */
	public function __construct(mysqli $mysqli)
	{
		$this->mysqli = $mysqli;
	}

	/**
	 * Builds an SQL query by substituting placeholders with provided values.
	 *
	 * @param  string $query The SQL query template with placeholders.
	 * @param  array  $args  The values to substitute in place of placeholders.
	 *
	 * @return string    The resulting SQL query after substitution.
	 * @throws Exception If there are insufficient arguments for the placeholders.
	 */
	public function buildQuery(string $query, array $args = []): string
	{
		// Replace placeholders with values from $args.
		$index    = 0; // Index to track the current argument.
		$callback = function ($matches) use (&$args, &$index) {
			if ($index >= count($args)) {
				throw new Exception('Insufficient arguments provided for placeholders');
			}

			$value = $args[$index++];
			$type  = $matches[1]; // Capture the type specifier.

			if ($value === self::SKIP_VALUE) { // Keep special value to exclude block.
				return $value;
			}

			// Handle different types of placeholders.
			switch ($type) {
				case 'd': // Integer.
					return intval($value);
				case 'f': // Float.
					return floatval($value);
				case 'a': // Array.
					if (!is_array($value)) {
						throw new Exception('Expected array for placeholder ?a');
					}
					return $this->formatArray($value);
				case '#': // Identifier or array of identifiers.
					return $this->formatIdentifiers($value);
				default: // String, int, float, bool, null.
					return $this->escapeValue($value);
			}
		};

		// Replace placeholders and handle conditional blocks
		$query = preg_replace_callback('/\?([df#a]?)/', $callback, $query);
		$query = $this->handleConditionalBlocks($query, $args);

		return $query;
	}

	/**
	 * Formats an array into a string for SQL queries.
	 *
	 * @param array $array The array to format.
	 *
	 * @return string The formatted string.
	 */
	private function formatArray($array): string
	{
		// Handle associative and indexed arrays differently.
		if ($this->isAssoc($array)) {
			// Associative array.
			return implode(
				', ',
				array_map(
					function ($key, $value) {
						return "`$key` = " . $this->escapeValue($value);
					},
					array_keys($array),
					$array
				)
			);
		} else {
			// Indexed array.
			return implode(', ', array_map([$this, 'escapeValue'], $array));
		}
	}

	/**
	 * Formats an identifier or an array of identifiers for SQL queries.
	 *
	 * @param mixed $value The identifier(s) to format.
	 *
	 * @return string The formatted identifier string.
	 */
	private function formatIdentifiers($value): string
	{
		// Handle single identifier or array of identifiers.
		if (is_array($value)) {
			return implode(
				', ',
				array_map(
					function ($item) {
						return "`$item`";
					},
					$value
				)
			);
		} else {
			return "`$value`";
		}
	}

	/**
	 * Escapes a value for safe inclusion in an SQL query.
	 *
	 * @param mixed $value The value to escape.
	 *
	 * @return string The escaped value.
	 */
	private function escapeValue($value)
	{
		// Escape and quote strings, handle other types.
		if (is_string($value)) {
			return "'" . $this->mysqli->real_escape_string($value) . "'";
		} elseif (is_null($value)) {
			return 'NULL';
		} elseif (is_bool($value)) {
			return $value ? '1' : '0';
		} else {
			return $value;
		}
	}

	/**
	 * Checks if an array is associative.
	 *
	 * @param array $array The array to check.
	 *
	 * @return bool True if associative, false otherwise.
	 */
	private function isAssoc(array $array): bool
	{
		// Check if an array is associative.
		if (array() === $array) {
			return false;
		}
		return array_keys($array) !== range(0, count($array) - 1);
	}

	/**
	 * Handles conditional blocks in SQL query templates.
	 *
	 * @param string $query The SQL query template.
	 * @param array  $args  The arguments array.
	 *
	 * @return string The query with conditional blocks processed.
	 */
	private function handleConditionalBlocks(string $query, array $args): string
	{
		return preg_replace_callback(
			'/\{([^{}]*)\}/',
			function ($matches) use ($args, &$index) {
				$block      = $matches[1];
				$hasSpecial = str_contains($block, self::SKIP_VALUE);

				return $hasSpecial ? '' : $block; // Include or skip the block.
			},
			$query
		);
	}

	/**
	 * Returns the special skip value to indicate the exclusion of a conditional block.
	 *
	 * @return string The skip value.
	 */
	public function skip(): string
	{
		return self::SKIP_VALUE;
	}
}
