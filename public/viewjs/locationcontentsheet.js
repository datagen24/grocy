// View script for the location content sheet (views/locationcontentsheet.blade.php):
// printable per-location stock listing with print buttons and an "include out of stock" filter.

// Print all locations: make every .page section printable, stamp the print timestamp
$(document).on("click", ".print-all-locations-button", function(e)
{
	$(".page").removeClass("d-print-none").removeClass("no-page-break");
	$(".print-timestamp").text(moment().format("l LT"));
	window.print();
});

// Print a single location: hide all .page sections except the one containing the clicked button
$(document).on("click", ".print-single-location-button", function(e)
{
	$(".page").addClass("d-print-none");
	$(e.currentTarget).closest(".page").removeClass("d-print-none").addClass("no-page-break");
	$(".print-timestamp").text(moment().format("l LT"));
	window.print();
});

// The filter is applied server side (StockController) - toggle the include_out_of_stock
// URI param and reload; the checkbox state mirrors the param inversely (param set = unchecked)
$("#include-out-of-stock").change(function()
{
	if (this.checked)
	{
		RemoveUriParam("include_out_of_stock");
	}
	else
	{
		UpdateUriParam("include_out_of_stock", true);
	}

	window.location.reload();
});

if (GetUriParam("include_out_of_stock"))
{
	$("#include-out-of-stock").prop("checked", false);
}
