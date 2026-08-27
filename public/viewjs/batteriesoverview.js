// View script for the batteries overview page (views/batteriesoverview.blade.php):
// due/overdue status table, quick charge tracking via POST /api/batteries/{id}/charge,
// grocycode label printing and live statistics refresh via GET /api/batteries

// DataTables setup for the batteries overview
var batteriesOverviewTable = $('#batteries-overview-table').DataTable({
	'order': [[4, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ "type": "html", "targets": 3 },
		{ "type": "html", "targets": 4 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#batteries-overview-table tbody').removeClass("d-none");
batteriesOverviewTable.columns.adjust().draw();

// Debounced free-text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	batteriesOverviewTable.search(value).draw();
}, Grocy.FormFocusDelay));

// Reset search and status filter
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#status-filter").val("all");
	batteriesOverviewTable.column(batteriesOverviewTable.colReorder.transpose(5)).search("").draw();
	batteriesOverviewTable.search("").draw();
});

// Due status filter searches the hidden status column (column 5)
$("#status-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	batteriesOverviewTable.column(batteriesOverviewTable.colReorder.transpose(5)).search(value).draw();
});

// Clicking a statistics message (e.g. "x batteries are overdue") applies the corresponding status filter
$(".status-filter-message").on("click", function()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Track a charge cycle now via POST /api/batteries/{id}/charge, then re-fetch the battery
// (GET /api/batteries/{id}) to update the row's due status/coloring in place without a reload
// (expects data-battery-id / data-battery-name attributes from the Blade template)
$(document).on('click', '.track-charge-cycle-button', function(e)
{
	e.preventDefault();

	Grocy.FrontendHelpers.BeginUiBusy();

	var batteryId = $(e.currentTarget).attr('data-battery-id');
	var batteryName = $(e.currentTarget).attr('data-battery-name');
	var trackedTime = moment().format('YYYY-MM-DD HH:mm:ss');

	Grocy.Api.Post('batteries/' + batteryId + '/charge', { 'tracked_time': trackedTime },
		function()
		{
			Grocy.Api.Get('batteries/' + batteryId,
				function(result)
				{
					var batteryRow = $('#battery-' + batteryId + '-row');
					var nextXDaysThreshold = moment().add($("#info-due-soon-batteries").data("next-x-days"), "days");
					var now = moment();
					var nextExecutionTime = moment(result.next_estimated_charge_time);

					batteryRow.removeClass("table-warning");
					batteryRow.removeClass("table-danger");
					if (nextExecutionTime.isBefore(now))
					{
						batteryRow.addClass("table-danger");
					}
					else if (nextExecutionTime.isBefore(nextXDaysThreshold))
					{
						batteryRow.addClass("table-warning");
					}

					animateCSS("#battery-" + batteryId + "-row td:not(:first)", "flash");

					$('#battery-' + batteryId + '-last-tracked-time').text(trackedTime);
					$('#battery-' + batteryId + '-last-tracked-time-timeago').attr('datetime', trackedTime);
					if (result.battery.charge_interval_days != 0)
					{
						$('#battery-' + batteryId + '-next-charge-time').text(result.next_estimated_charge_time);
						$('#battery-' + batteryId + '-next-charge-time-timeago').attr('datetime', result.next_estimated_charge_time);
					}

					Grocy.FrontendHelpers.EndUiBusy();
					toastr.success(__t('Tracked charge cycle of battery %1$s on %2$s', batteryName, trackedTime));
					RefreshContextualTimeago("#battery-" + batteryId + "-row");
					RefreshStatistics();
				},
				function(xhr)
				{
					Grocy.FrontendHelpers.EndUiBusy();
					console.error(xhr);
				}
			);
		},
		function(xhr)
		{
			Grocy.FrontendHelpers.EndUiBusy();
			console.error(xhr);
		}
	);
});

// Print a battery grocycode label: GET /api/batteries/{id}/printlabel, then pass the label data to the configured label printer webhook
$(document).on('click', '.battery-grocycode-label-print', function(e)
{
	e.preventDefault();

	var batteryId = $(e.currentTarget).attr('data-battery-id');
	Grocy.Api.Get('batteries/' + batteryId + '/printlabel', function(labelData)
	{
		if (Grocy.Webhooks.labelprinter !== undefined)
		{
			Grocy.FrontendHelpers.RunWebhook(Grocy.Webhooks.labelprinter, labelData);
		}
	});
});

/**
 * Recalculates the due today / due soon / overdue counters shown above the table
 * from GET /api/batteries (the "due soon" horizon comes from the data-next-x-days
 * attribute rendered by the Blade template).
 */
function RefreshStatistics()
{
	var nextXDays = $("#info-due-soon-batteries").data("next-x-days");
	Grocy.Api.Get('batteries',
		function(result)
		{
			var dueTodayCount = 0;
			var dueSoonCount = 0;
			var overdueCount = 0;
			var overdueThreshold = moment();
			var nextXDaysThreshold = moment().add(nextXDays, "days");
			var todayThreshold = moment().endOf("day");

			result.forEach(element =>
			{
				var date = moment(element.next_estimated_charge_time);

				if (date.isBefore(overdueThreshold))
				{
					overdueCount++;
				}
				else if (date.isSameOrBefore(todayThreshold))
				{
					dueTodayCount++;
					dueSoonCount++;
				}
				else if (date.isSameOrBefore(nextXDaysThreshold))
				{
					dueSoonCount++;
				}
			});

			$("#info-due-today-batteries").html('<span class="d-block d-md-none">' + dueTodayCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueTodayCount, '%s battery is due to be charged today', '%s batteries are due to be charged today'));
			$("#info-due-soon-batteries").html('<span class="d-block d-md-none">' + dueSoonCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueSoonCount, '%s battery is due to be charged', '%s batteries are due to be charged') + ' ' + __n(nextXDays, 'within the next day', 'within the next %s days'));
			$("#info-overdue-batteries").html('<span class="d-block d-md-none">' + overdueCount + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(overdueCount, '%s battery is overdue to be charged', '%s batteries are overdue to be charged'));
		},
		function(xhr)
		{
			console.error(xhr);
		}
	);
}

// Initial statistics load
RefreshStatistics();
