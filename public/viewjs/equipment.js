// View script for the equipment overview (views/equipment.blade.php):
// master/detail page - a DataTable of all equipment on the left, the selected item's
// description and instruction manual PDF (plus file type userfields) on the right.
//
// A partial clone rather than a pure one (plan 12, Q5): it takes the shared list pieces
// for the table, the search box and the delete confirmation, and keeps the master/detail
// behaviour, which no other list has.

// DataTables setup with single row selection; selecting a row loads its details,
// the first row is selected initially
var equipmentTable = Victual.EntityList.Table('#equipment-table', {
	select: {
		style: 'single',
		selector: 'tr td:not(:first-child)'
	},
	'initComplete': function ()
	{
		this.api().row({ order: 'current' }, 0).select();

		// Only real rows carry data-equipment-id (views/equipment.blade.php). With no
		// equipment at all, tr:eq(0) is DataTables' own "no data" placeholder row, so
		// this used to read undefined and ask the API for /objects/equipment/undefined -
		// which answered 500 and put the failing SQL in front of the user. Issue #48
		// fixes the answer; this is the page no longer asking the question.
		var firstEquipmentId = $('#equipment-table tbody tr:eq(0)').data("equipment-id");
		if (firstEquipmentId !== undefined)
		{
			DisplayEquipment(firstEquipmentId);
		}
	}
});

// Row selection -> show that equipment item (rows carry data-equipment-id from the Blade template)
equipmentTable.on('select', function (e, dt, type, indexes)
{
	if (type === 'row')
	{
		var selectedEquipmentId = $(equipmentTable.row(indexes[0]).node()).data("equipment-id");
		DisplayEquipment(selectedEquipmentId)
	}
});

/**
 * Loads one equipment item (GET /api/objects/equipment/{id}) and fills the detail pane:
 * name, HTML description, edit link and the instruction manual embed/download
 * (PDF served via /api/files/equipmentmanuals/{base64 file name}).
 * Also loads file type userfields of the equipment entity and toggles their
 * embed/download/empty-hint elements accordingly (files served via /api/files/userfiles/).
 * @param {number|string} id - The equipment object id
 */
function DisplayEquipment(id)
{
	Victual.Api.Get('objects/equipment/' + id,
		function (equipmentItem)
		{
			$(".selected-equipment-name").text(equipmentItem.name);
			$("#description-tab-content").html(equipmentItem.description);
			$(".equipment-edit-button").attr("href", U("/equipment/" + equipmentItem.id.toString()));

			if (equipmentItem.instruction_manual_file_name)
			{
				var pdfUrl = U('/api/files/equipmentmanuals/' + btoa(equipmentItem.instruction_manual_file_name));
				$("#selected-equipment-instruction-manual").attr("src", pdfUrl);
				$("#selectedEquipmentInstructionManualDownloadButton").attr("href", pdfUrl);
				$("#selected-equipment-instruction-manual").removeClass("d-none");
				$("#selectedEquipmentInstructionManualDownloadButton").removeClass("d-none");
				$("#selected-equipment-has-no-instruction-manual-hint").addClass("d-none");

				$("a[href='#instruction-manual-tab']").tab("show");
				ResizeResponsiveEmbeds();
			}
			else
			{
				$("#selected-equipment-instruction-manual").addClass("d-none");
				$("#selectedEquipmentInstructionManualDownloadButton").addClass("d-none");
				$("#selected-equipment-has-no-instruction-manual-hint").removeClass("d-none");

				$("a[href='#description-tab']").tab("show");
			}

			if (equipmentItem.userfields != null)
			{
				Victual.Api.Get('objects/userfields?query[]=entity=equipment&query[]=type=file',
					function (result)
					{
						$.each(result, function (key, userfield)
						{
							var userfieldFile = equipmentItem.userfields[userfield.name];
							if (userfieldFile)
							{
								var pdfUrl = U('/api/files/userfiles/' + userfieldFile);
								$("#file-userfield-" + userfield.name + "-embed").attr("src", pdfUrl);
								$("#file-userfield-" + userfield.name + "-download-button").attr("href", pdfUrl);
								$("#file-userfield-" + userfield.name + "-embed").removeClass("d-none");
								$("#file-userfield-" + userfield.name + "-download-button").removeClass("d-none");
								$("#file-userfield-" + userfield.name + "-empty-hint").addClass("d-none");
								ResizeResponsiveEmbeds();
							}
							else
							{
								$("#file-userfield-" + userfield.name + "-embed").addClass("d-none");
								$("#file-userfield-" + userfield.name + "-download-button").addClass("d-none");
								$("#file-userfield-" + userfield.name + "-empty-hint").removeClass("d-none");
							}
						});
					}
				);
			}
		}
	);
}

// Search box and "clear filters", from the shared list pieces
Victual.EntityList.SearchFilter(equipmentTable);

// Delete an equipment item after confirmation (DELETE /api/objects/equipment/{id});
// the buttons carry data-equipment-id/-name from the Blade template, and the shared
// confirmation escapes the name into its message
Victual.EntityList.ConfirmDelete({
	button: '.equipment-delete-button',
	idAttr: 'data-equipment-id',
	nameAttr: 'data-equipment-name',
	endpoint: 'objects/equipment',
	message: 'Are you sure you want to delete equipment "%s"?',
	list: '/equipment'
});

// Toggle fullscreen display of the instruction manual / userfield file card
$(".selectedEquipmentInstructionManualToggleFullscreenButton").on('click', function (e)
{
	var button = $(e.currentTarget);
	var card = button.closest(".selectedEquipmentInstructionManualCard");

	card.toggleClass("fullscreen");
	card.find(".card-header").toggleClass("fixed-top");
	card.find(".card-body").toggleClass("mt-5");
	$("body").toggleClass("fullscreen-card");
	$("embed.embed-responsive").removeClass("resize-done");
	ResizeResponsiveEmbeds();
});

// Toggle fullscreen display of the description card
$("#selectedEquipmentDescriptionToggleFullscreenButton").on('click', function (e)
{
	$("#selectedEquipmentDescriptionCard").toggleClass("fullscreen");
	$("#selectedEquipmentDescriptionCard .card-header").toggleClass("fixed-top");
	$("#selectedEquipmentDescriptionCard .card-body").toggleClass("mt-5");
	$("body").toggleClass("fullscreen-card");
});
