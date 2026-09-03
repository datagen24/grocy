// View script for the chores master data list (views/chores.blade.php):
// DataTable setup, search filter, delete via DELETE /api/objects/chores/{id},
// show/hide disabled chores - all of it the shared list factory
// (public/js/victual_entity.js) - plus merging two chores, which is this page's own.

Victual.EntityList({
	table: '#chores-table',
	list: '/chores',
	showDisabled: true,
	resetShowDisabled: true,
	delete: {
		button: '.chore-delete-button',
		idAttr: 'data-chore-id',
		nameAttr: 'data-chore-name',
		endpoint: 'objects/chores',
		message: 'Are you sure you want to delete chore "%s"?'
	}
});

// Open the merge chores modal, prefilled with the clicked row's chore as the one to keep
$(".merge-chores-button").on("click", function (e)
{
	var choreId = $(e.currentTarget).attr("data-chore-id");
	$("#merge-chores-keep").val(choreId);
	$("#merge-chores-remove").val("");
	$("#merge-chores-modal").modal("show");
});

$("#merge-chores-save-button").on("click", function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("merge-chores-form", true))
	{
		return;
	}

	var choreIdToKeep = $("#merge-chores-keep").val();
	var choreIdToRemove = $("#merge-chores-remove").val();

	Victual.Api.Post("chores/" + choreIdToKeep.toString() + "/merge/" + choreIdToRemove.toString(), {},
		function (result)
		{
			window.location.href = U('/chores');
		},
		function (xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while merging', xhr.response);
		}
	);
});
