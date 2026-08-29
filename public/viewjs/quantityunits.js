// Powers the quantity units list view (views/quantityunits.blade.php):
// DataTable of all quantity units with search, delete confirmation and a "show disabled" toggle.

// DataTables setup for the quantity units list (column 0 = row menu, not sortable/searchable)
var quantityUnitsTable = $('#quantityunits-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#quantityunits-table tbody').removeClass("d-none");
quantityUnitsTable.columns.adjust().draw();

// Debounced full-text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	quantityUnitsTable.search(value).draw();
}, Victual.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	quantityUnitsTable.search("").draw();
});

// Delete button per row (expects data-quantityunit-name / data-quantityunit-id from the template);
// confirms via bootbox, then DELETEs objects/quantity_units/{id}
$(document).on('click', '.quantityunit-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-quantityunit-name');
	var objectId = $(e.currentTarget).attr('data-quantityunit-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete quantity unit "%s"?', objectName),
		closeButton: false,
		buttons: {
			confirm: {
				label: 'Yes',
				className: 'btn-success'
			},
			cancel: {
				label: 'No',
				className: 'btn-danger'
			}
		},
		callback: function(result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/quantity_units/' + objectId, {},
					function(result)
					{
						window.location.href = U('/quantityunits');
					},
					function(xhr)
					{
						console.error(xhr);
					}
				);
			}
		}
	});
});

// "Show disabled" toggle reloads the page with/without the include_disabled URI parameter (filtering happens server-side)
$("#show-disabled").change(function()
{
	if (this.checked)
	{
		window.location.href = U('/quantityunits?include_disabled');
	}
	else
	{
		window.location.href = U('/quantityunits');
	}
});

if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}
