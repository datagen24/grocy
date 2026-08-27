// Powers the stock journal summary view (stockjournalsummary.blade.php): an aggregated,
// per-product summary of stock transactions, filterable by product/transaction type/user.
// All filtering here is purely client-side against the already-rendered table.
var journalSummaryTable = $('#stock-journal-summary-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#stock-journal-summary-table tbody').removeClass("d-none");
journalSummaryTable.columns.adjust().draw();

// Product filter, matched as an exact-text regex against the product column (index 1)
// so that one product's name being a substring of another's doesn't cause false matches
$("#product-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#product-filter option:selected").text();
	if (value === "all")
	{
		journalSummaryTable.column(journalSummaryTable.colReorder.transpose(1)).search("").draw();
	}
	else
	{
		journalSummaryTable.column(journalSummaryTable.colReorder.transpose(1)).search("^" + $.fn.dataTable.util.escapeRegex(text) + "$", true, false).draw();
	}
});

// Transaction type filter, matched against the type column (index 2)
$("#transaction-type-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#transaction-type-filter option:selected").text();
	if (value === "all")
	{
		text = "";
	}

	journalSummaryTable.column(journalSummaryTable.colReorder.transpose(2)).search(text).draw();
});

// User filter, matched against the user column (index 3)
$("#user-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#user-filter option:selected").text();
	if (value === "all")
	{
		text = "";
	}

	journalSummaryTable.column(journalSummaryTable.colReorder.transpose(3)).search(text).draw();
});

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	journalSummaryTable.search(value).draw();
}, Grocy.FormFocusDelay));

// Resets all filters (note: #location-filter doesn't exist on this view, so that line
// is a harmless no-op left over from the shared filter-clearing pattern)
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#transaction-type-filter").val("all");
	$("#location-filter").val("all");
	$("#user-filter").val("all");
	$("#product-filter").val("all");
	journalSummaryTable.column(journalSummaryTable.colReorder.transpose(1)).search("").draw();
	journalSummaryTable.column(journalSummaryTable.colReorder.transpose(2)).search("").draw();
	journalSummaryTable.column(journalSummaryTable.colReorder.transpose(3)).search("").draw();
	journalSummaryTable.search("").draw();
});
