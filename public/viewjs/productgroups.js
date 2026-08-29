// Powers the product groups list view (views/productgroups.blade.php):
// DataTable of all product groups with search, delete confirmation and a "show disabled" toggle.

// DataTables setup for the product groups list (column 0 = row menu, not sortable/searchable)
var groupsTable = $('#productgroups-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#productgroups-table tbody').removeClass("d-none");
groupsTable.columns.adjust().draw();

// Debounced full-text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	groupsTable.search(value).draw();
}, Victual.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	groupsTable.search("").draw();
});

// Delete button per row (expects data-group-name / data-group-id from the template);
// confirms via bootbox, then DELETEs objects/product_groups/{id}
$(document).on('click', '.product-group-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-group-name');
	var objectId = $(e.currentTarget).attr('data-group-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete product group "%s"?', objectName),
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
				Victual.Api.Delete('objects/product_groups/' + objectId, {},
					function(result)
					{
						window.location.href = U('/productgroups');
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
// Reload the list when an embedded edit modal (product group form) closes
$(window).on("message", function(e)
{
	var data = e.originalEvent.data;

	if (data.Message === "CloseLastModal")
	{
		window.location.reload();
	}
});

// "Show disabled" toggle reloads the page with/without the include_disabled URI parameter (filtering happens server-side)
$("#show-disabled").change(function()
{
	if (this.checked)
	{
		window.location.href = U('/productgroups?include_disabled');
	}
	else
	{
		window.location.href = U('/productgroups');
	}
});

if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}
