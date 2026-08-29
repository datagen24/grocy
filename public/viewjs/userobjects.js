// Powers the userobjects list view (userobjects.blade.php): lists the records ("objects")
// belonging to one custom user entity. Table listing, search filtering, deletion.
var userobjectsTable = $('.userobjects-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('.userobjects-table tbody').removeClass("d-none");
userobjectsTable.columns.adjust().draw();

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	userobjectsTable.search(value).draw();
}, Victual.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	userobjectsTable.search("").draw();
});

// Deletes a userobject (DELETE objects/userobjects/{id}) after confirmation
$(document).on('click', '.userobject-delete-button', function(e)
{
	var objectId = $(e.currentTarget).attr('data-userobject-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete this userobject?'),
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
				Victual.Api.Delete('objects/userobjects/' + objectId, {},
					function(result)
					{
						window.location.reload();
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
