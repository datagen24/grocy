// View script for the meal plan sections list (views/mealplansections.blade.php):
// DataTables listing of meal plan sections (e.g. breakfast/lunch/dinner) with search and delete.

// DataTables setup - default sort by the sort_number column, first column (row menu)
// is neither orderable nor searchable
var mealplanSectionsTable = $('#mealplansections-table').DataTable({
	'order': [[2, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#mealplansections-table tbody').removeClass("d-none");
mealplanSectionsTable.columns.adjust().draw();

// Debounced free text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	mealplanSectionsTable.search(value).draw();
}, Grocy.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	mealplanSectionsTable.search("").draw();
});

// Delete a section after confirmation (DELETE /api/objects/meal_plan_sections/{id});
// the buttons carry data-mealplansection-id/-name from the Blade template
$(document).on('click', '.mealplansection-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-mealplansection-name');
	var objectId = $(e.currentTarget).attr('data-mealplansection-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete meal plan section "%s"?', objectName),
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
				Grocy.Api.Delete('objects/meal_plan_sections/' + objectId, {},
					function(result)
					{
						window.location.href = U('/mealplansections');
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
