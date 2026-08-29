// View script for the locations list (views/locations.blade.php):
// DataTables listing of all stock locations with search, delete and a "show disabled" filter.

// DataTables setup - first column (row menu) is neither orderable nor searchable
var locationsTable = $('#locations-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#locations-table tbody').removeClass("d-none");
locationsTable.columns.adjust().draw();

// Debounced free text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	locationsTable.search(value).draw();
}, Victual.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	locationsTable.search("").draw();
});

// Delete a location after confirmation (DELETE /api/objects/locations/{id});
// the buttons carry data-location-id/-name from the Blade template
$(document).on('click', '.location-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-location-name');
	var objectId = $(e.currentTarget).attr('data-location-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete location "%s"?', objectName),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function(result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/locations/' + objectId, {},
					function(result)
					{
						window.location.href = U('/locations');
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

// "Show disabled" is filtered server side via the include_disabled URI param
$("#show-disabled").change(function()
{
	if (this.checked)
	{
		window.location.href = U('/locations?include_disabled');
	}
	else
	{
		window.location.href = U('/locations');
	}
});

if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}
