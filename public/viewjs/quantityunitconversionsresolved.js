// Powers the resolved quantity unit conversions view (views/quantityunitconversionsresolved.blade.php):
// a read-only DataTable of all (transitively resolved) unit conversions with a quantity-unit filter.

// DataTables setup from the shared list piece (column 0 = row menu, not sortable/searchable).
// This list is read only - no delete, and no free-text search box - so it takes the table
// and nothing else.
var quConversionsResolvedTable = Victual.EntityList.Table('#qu-conversions-resolved-table');

// Filter by the selected quantity unit name against both the "from" and "to" unit columns
$("#quantity-unit-filter").on("change", function()
{
	var value = $("#quantity-unit-filter option:selected").text();
	if (value === __t("All"))
	{
		value = "";
	}

	quConversionsResolvedTable.column([quConversionsResolvedTable.colReorder.transpose(1), quConversionsResolvedTable.colReorder.transpose(2)]).search(value).draw();
});

$("#clear-filter-button").on("click", function()
{
	$("#quantity-unit-filter").val("all");
	quConversionsResolvedTable.column([quConversionsResolvedTable.colReorder.transpose(1), quConversionsResolvedTable.colReorder.transpose(2)]).search("").draw();
	quConversionsResolvedTable.search("").draw();
});
