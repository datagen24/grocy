// Powers the shopping locations (stores) list view (shoppinglocations.blade.php):
// table listing, search filtering, delete confirmation, and the disabled-items toggle.
var locationsTable = $('#shoppinglocations-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#shoppinglocations-table tbody').removeClass("d-none");
locationsTable.columns.adjust().draw();

// Free-text search box, debounced via Delay()
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

// Deletes a shopping location (DELETE objects/shopping_locations/{id}) after confirmation
$(document).on('click', '.shoppinglocation-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-shoppinglocation-name');
	var objectId = $(e.currentTarget).attr('data-shoppinglocation-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete store "%s"?', objectName),
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
				Victual.Api.Delete('objects/shopping_locations/' + objectId, {},
					function(result)
					{
						window.location.href = U('/shoppinglocations');
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

// Toggling "show disabled" reloads the page with/without the include_disabled query param
$("#show-disabled").change(function()
{
	if (this.checked)
	{
		window.location.href = U('/shoppinglocations?include_disabled');
	}
	else
	{
		window.location.href = U('/shoppinglocations');
	}
});

// Reflect the current include_disabled state onto the checkbox on load
if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}
