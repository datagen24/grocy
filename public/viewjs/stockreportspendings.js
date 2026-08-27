// Powers the stock spendings report view (stockreportspendings.blade.php): renders a
// doughnut chart plus data table summarizing purchase spending (grouped by product or
// product group, per the "group-by" URI param), over a selectable date range. All
// filtering (date range/product group/group-by) is server-side and reloads the page.

// Reads the already-rendered metrics table to build the chart's labels/data, since the
// table (server-rendered) is the single source of truth for the aggregated amounts
var labels = [];
var data = [];
var totalAmount = 0.0;
$("#metrics-table tbody tr").each(function()
{
	var self = $(this);
	labels.push(self.find("td:eq(0)").text().trim());
	var itemTotal = Number.parseFloat(self.find("td:eq(1)").attr("data-chart-value"));
	data.push(itemTotal);
	totalAmount += + itemTotal;
});
totalAmount = totalAmount.toLocaleString(undefined, { style: "currency", currency: Grocy.Currency });

// Assign a color to each slice from the Paired12 scheme, cycling (skipping index 0
// on restart) once there are more items than colors
var backgroundColors = [];
var colorChoiceIndex = 0;
for (i = 0; i < data.length; i++)
{
	if (i + 1 == Chart.colorschemes.brewer.Paired12.length)
	{
		// Restart background color choices
		colorChoiceIndex = 1;
	}
	backgroundColors.push(Chart.colorschemes.brewer.Paired12[colorChoiceIndex]);
	colorChoiceIndex++;
}

// Outlabeled doughnut chart (Chart.js) with the grand total shown in the center
var metricsChart = new Chart("metrics-chart", {
	"type": "outlabeledDoughnut",
	"options": {
		"legend": {
			"display": false
		},
		"tooltips": {
			"enabled": false
		},
		"tooltips": {
			"enabled": false
		},
		"plugins": {
			"outlabels": {
				"text": "%l %p",
				"backgroundColor": "#343a40",
				"font": {
					"minSize": 12,
					"maxSize": 18
				}
			},
			"doughnutlabel": {
				"labels": [
					{
						"text": totalAmount,
						"font": {
							"size": 24,
							"weight": "bold"
						},
					},
					{
						"text": __t("Total")
					}
				]
			}
		}
	},
	"data": {
		"labels": labels,
		"datasets": [{
			"data": data,
			"backgroundColor": backgroundColors
		}]
	}
});


// Enhances the server-rendered metrics table with DataTables sorting
var metricsTable = $("#metrics-table").DataTable({
	"columnDefs": [
		{ "type": "num", "targets": 1 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$("#metrics-table tbody").removeClass("d-none");
metricsTable.columns.adjust().draw();

// Date range defaults to the current month, unless overridden by start_date/end_date URI params
var startDate = moment().startOf("month").format("YYYY-MM-DD");
var endDate = moment().endOf("month").format("YYYY-MM-DD");
if (GetUriParam("start_date"))
{
	startDate = moment(GetUriParam("start_date"));
}
if (GetUriParam("end_date"))
{
	endDate = moment(GetUriParam("end_date"));
}

// Preset quick-pick ranges offered by the daterangepicker below
var ranges = {};
ranges[__t("Today")] = [moment(), moment()];
ranges[__t("Yesterday")] = [moment().subtract(1, "days"), moment().subtract(1, "days")];
ranges[__n(7, "Last %1$s day", "Last %1$s days")] = [moment().subtract(6, "days"), moment()];
ranges[__n(14, "Last %1$s day", "Last %1$s days")] = [moment().subtract(13, "days"), moment()];
ranges[__n(30, "Last %1$s day", "Last %1$s days")] = [moment().subtract(29, "days"), moment()];
ranges[__t("This month")] = [moment().startOf("month"), moment().endOf("month")];
ranges[__t("Last month")] = [moment().subtract(1, "month").startOf("month"), moment().subtract(1, "month").endOf("month")];
ranges[__t("This year")] = [moment().startOf("year"), moment().endOf("year")];
ranges[__t("Last year")] = [moment().subtract(1, "year").startOf("year"), moment().subtract(1, "year").endOf("year")];

// Applying a date range updates the start_date/end_date URI params and reloads (the
// report itself is computed server-side for the given range)
$("#daterange-filter").daterangepicker({
	"showDropdowns": true,
	"alwaysShowCalendars": true,
	"buttonClasses": "btn",
	"applyButtonClasses": "btn-primary",
	"cancelButtonClasses": "btn-secondary",
	"startDate": startDate,
	"endDate": endDate,
	"showWeekNumbers": Grocy.CalendarShowWeekNumbers,
	"locale": {
		"format": "YYYY-MM-DD",
		"firstDay": Grocy.CalendarFirstDayOfWeek
	},
	"applyLabel": __t("Apply"),
	"cancelLabel": __t("Cancel"),
	"ranges": ranges
}, function(start, end, label)
{
	UpdateUriParam("start_date", start.format("YYYY-MM-DD"));
	UpdateUriParam("end_date", end.format("YYYY-MM-DD"))
	window.location.reload();
});
$('[data-range-key="Custom Range"]').text(__t("Custom range")); // customRangeLabel option doesn't work, however

// Cancelling the picker restores the input text to the currently active range
$("#daterange-filter").on("cancel.daterangepicker", function(ev, picker)
{
	$(this).val(startDate + " - " + endDate);
});

// Resets all filter-related URI params and reloads
$("#clear-filter-button").on("click", function()
{
	RemoveUriParam("start_date");
	RemoveUriParam("end_date");
	RemoveUriParam("product-group");
	window.location.reload();
});

// Product group filter is server-side, so it updates the "product-group" URI param and reloads
$("#product-group-filter").on("change", function()
{
	UpdateUriParam("product-group", $(this).val());
	window.location.reload();
});

// Switches the report's grouping (e.g. by product vs. by product group) via the
// "group-by" URI param and reloads
$(".group-by-button").on("click", function(e)
{
	e.preventDefault();

	UpdateUriParam("group-by", $(this).attr("data-group-by"));
	window.location.reload();
});
