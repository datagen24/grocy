<?php

namespace Victual\Services\Database;

/**
 * Semantic types for columns neither engine's catalogue can answer for.
 *
 * The API validates the fields a caller names in "query[]" and "order" against the
 * entity's columns, and for the substring and regex operators it has to know whether a
 * column is text. Both engines are asked their own catalogue, and for a base table both
 * answer. For a *view* column that is a computed expression - a GROUP_CONCAT, a COALESCE,
 * a concatenation - SQLite's PRAGMA table_info returns an empty string, because there is
 * no declared type to report; PostgreSQL resolves the expression and reports what it came
 * out as. Comparing two catalogues therefore cannot make the two engines agree on those
 * columns, and an engine-dependent answer to "may I search this field" is the same
 * category of silent divergence the operator itself was fixed for.
 *
 * So the answer for those columns is written down here instead, once, and applied to both
 * engines identically. Three rules keep it honest:
 *
 *   1. **Semantic, not SQL.** Entries say "text", not "TEXT" or "character varying". The
 *      point is a claim about what the column *is*, independent of how either engine
 *      spells it, so that neither engine's vocabulary becomes the contract by default.
 *   2. **It fills gaps, it does not override.** An entry is used only where the engine's
 *      catalogue has nothing to say. A catalogue that knows the type always wins, so a
 *      wrong entry here cannot make an engine accept something it will then fail on.
 *   3. **Silence means rejected.** A computed column that is not listed stays unsearchable
 *      on both engines until somebody classifies it deliberately. Adding a column to a
 *      view does not quietly make it searchable on one engine.
 *
 * `.devtools/pgsql/filterdifftest.php` enforces all three against the real schema on both
 * engines: every entry must name a column that exists, must not contradict an engine that
 * does have a real type for it, and the two engines' eligibility verdicts must match for
 * every column of every shared table and view.
 */
class ColumnTypeManifest
{
	/**
	 * The semantic type "text", the only one the eligibility rule needs so far. Spelled as
	 * a constant rather than a literal so that adding a second one is a deliberate act.
	 */
	const TEXT = 'text';

	/**
	 * Computed view columns that are text, by view and column.
	 *
	 * Each was checked two ways before being listed: PostgreSQL independently resolves the
	 * expression to `text`, and the view's own definition produces a string. They are the
	 * columns a caller would actually want to search - names, display names, and the
	 * comma-separated barcode and product lists the UI helper views build.
	 *
	 * @var array<string, array<string, string>>
	 */
	const TYPES = [
		'product_barcodes_comma_separated' => ['barcodes' => self::TEXT],
		'products_volatile_status' => ['current_due_status' => self::TEXT],
		'quantity_unit_conversions_resolved' => ['path' => self::TEXT],
		'recipes_resolved' => ['product_names_comma_separated' => self::TEXT],
		'stock_missing_products' => ['name' => self::TEXT],
		'stock_splits' => ['id_group' => self::TEXT, 'stock_id_group' => self::TEXT, 'stock_id_to_keep' => self::TEXT],
		'uihelper_shopping_list' => ['product_barcodes' => self::TEXT],
		'uihelper_stock_current_overview' => ['product_barcodes' => self::TEXT],
		'uihelper_stock_journal' => ['user_display_name' => self::TEXT],
		'uihelper_stock_journal_summary' => ['user_display_name' => self::TEXT],
		'users_dto' => ['display_name' => self::TEXT],
	];

	/**
	 * The declared semantic types for one table or view, keyed by column name.
	 *
	 * @return array<string, string> Empty when nothing is declared for it, which is the
	 *                               common case and is not a problem.
	 */
	public static function For(string $table): array
	{
		return self::TYPES[$table] ?? [];
	}
}
