// View script for the batteries journal (views/batteriesjournal.blade.php):
// charge cycle log table with battery/date range filters (server-side via URI params) and
// undo of charge cycles via POST /api/batteries/charge-cycles/{id}/undo

// DataTables setup for the charge cycle journal
var batteriesJournalTable = $('#batteries-journal-table').DataTable({
	'order': [[2, 'desc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#batteries-journal-table tbody').removeClass("d-none");
batteriesJournalTable.columns.adjust().draw();

// Battery filter is applied server-side via the "battery" URI parameter (page reload)
$("#battery-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		RemoveUriParam("battery");
	}
	else
	{
		UpdateUriParam("battery", value);
	}

	window.location.reload();
});

// Debounced free-text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	batteriesJournalTable.search(value).draw();
}, Grocy.FormFocusDelay));

// Reset all filters (search, battery, date range) and reload
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#battery-filter").val("all");
	$("#daterange-filter").val("24");

	RemoveUriParam("months");
	RemoveUriParam("battery");
	window.location.reload();
});

// Date range filter is applied server-side via the "months" URI parameter (page reload)
$("#daterange-filter").on("change", function()
{
	UpdateUriParam("months", $(this).val());
	window.location.reload();
});

// Restore the filter dropdowns from the current URI parameters
if (typeof GetUriParam("battery") !== "undefined")
{
	$("#battery-filter").val(GetUriParam("battery"));
}

if (typeof GetUriParam("months") !== "undefined")
{
	$("#daterange-filter").val(GetUriParam("months"));
}

// Undo a charge cycle via POST /api/batteries/charge-cycles/{id}/undo and mark the row as undone in place
// (expects a data-charge-cycle-id attribute from the Blade template)
$(document).on('click', '.undo-battery-execution-button', function(e)
{
	e.preventDefault();

	var element = $(e.currentTarget);
	var chargeCycleId = $(e.currentTarget).attr('data-charge-cycle-id');

	Grocy.Api.Post('batteries/charge-cycles/' + chargeCycleId.toString() + '/undo', {},
		function(result)
		{
			element.closest("tr").addClass("text-muted");
			element.parent().siblings().find("span.name-anchor").addClass("text-strike-through").after("<br>" + __t("Undone on") + " " + moment().format("YYYY-MM-DD HH:mm:ss") + " <time class='timeago timeago-contextual' datetime='" + moment().format("YYYY-MM-DD HH:mm:ss") + "'></time>");
			element.closest(".undo-battery-execution-button").addClass("disabled");
			RefreshContextualTimeago("#charge-cycle-" + chargeCycleId + "-row");
			toastr.success(__t("Charge cycle successfully undone"));
		},
		function(xhr)
		{
			console.error(xhr);
		}
	);
});
