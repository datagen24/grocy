// View script for the equipment overview (views/equipment.blade.php):
// master/detail page - a DataTable of all equipment on the left, the selected item's
// description and instruction manual PDF (plus file type userfields) on the right.

// DataTables setup with single row selection; selecting a row loads its details,
// the first row is selected initially
var equipmentTable = $('#equipment-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs),
	select: {
		style: 'single',
		selector: 'tr td:not(:first-child)'
	},
	'initComplete': function ()
	{
		this.api().row({ order: 'current' }, 0).select();
		DisplayEquipment($('#equipment-table tbody tr:eq(0)').data("equipment-id"));
	}
});
$('#equipment-table tbody').removeClass("d-none");
equipmentTable.columns.adjust().draw();

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
	Grocy.Api.Get('objects/equipment/' + id,
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
				Grocy.Api.Get('objects/userfields?query[]=entity=equipment&query[]=type=file',
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
					},
					function (xhr)
					{
						console.error(xhr);
					}
				);
			}
		},
		function (xhr)
		{
			console.error(xhr);
		}
	);
}

// Debounced free text search over the table
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	equipmentTable.search(value).draw();
}, Grocy.FormFocusDelay));

$("#clear-filter-button").on("click", function ()
{
	$("#search").val("");
	equipmentTable.search("").draw();
});

// Delete an equipment item after confirmation (DELETE /api/objects/equipment/{id});
// the buttons carry data-equipment-id/-name from the Blade template
$(document).on('click', '.equipment-delete-button', function (e)
{
	var objectName = $(e.currentTarget).attr('data-equipment-name');
	var objectId = $(e.currentTarget).attr('data-equipment-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete equipment "%s"?', objectName),
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
		callback: function (result)
		{
			if (result === true)
			{
				Grocy.Api.Delete('objects/equipment/' + objectId, {},
					function (result)
					{
						window.location.href = U('/equipment');
					},
					function (xhr)
					{
						console.error(xhr);
					}
				);
			}
		}
	});
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
