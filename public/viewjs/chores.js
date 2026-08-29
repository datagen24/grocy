// View script for the chores master data list (views/chores.blade.php):
// DataTable setup, search filter, delete via DELETE /api/objects/chores/{id},
// show/hide disabled chores and merging two chores via POST /api/chores/{keep}/merge/{remove}

// DataTables setup for the chores list
var choresTable = $('#chores-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#chores-table tbody').removeClass("d-none");
choresTable.columns.adjust().draw();

// Debounced free-text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	choresTable.search(value).draw();
}, Victual.FormFocusDelay));

// Reset search and the "show disabled" checkbox
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	choresTable.search("").draw();
	$("#show-disabled").prop('checked', false);
});

// Delete button per row: confirm, then DELETE /api/objects/chores/{id}
// (expects data-chore-name / data-chore-id attributes from the Blade template)
$(document).on('click', '.chore-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-chore-name');
	var objectId = $(e.currentTarget).attr('data-chore-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete chore "%s"?', objectName),
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
				Victual.Api.Delete('objects/chores/' + objectId, {},
					function(result)
					{
						window.location.href = U('/chores');
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

// Toggle inclusion of disabled chores via page reload (server-side filter through the include_disabled URI parameter)
$("#show-disabled").change(function()
{
	if (this.checked)
	{
		window.location.href = U('/chores?include_disabled');
	}
	else
	{
		window.location.href = U('/chores');
	}
});

// Reflect the current include_disabled URI parameter in the checkbox state
if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}

// Open the merge chores modal, prefilled with the clicked row's chore as the one to keep
$(".merge-chores-button").on("click", function(e)
{
	var choreId = $(e.currentTarget).attr("data-chore-id");
	$("#merge-chores-keep").val(choreId);
	$("#merge-chores-remove").val("");
	$("#merge-chores-modal").modal("show");
});

$("#merge-chores-save-button").on("click", function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("merge-chores-form", true))
	{
		return;
	}

	var choreIdToKeep = $("#merge-chores-keep").val();
	var choreIdToRemove = $("#merge-chores-remove").val();

	Victual.Api.Post("chores/" + choreIdToKeep.toString() + "/merge/" + choreIdToRemove.toString(), {},
		function(result)
		{
			window.location.href = U('/chores');
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while merging', xhr.response);
		}
	);
});
