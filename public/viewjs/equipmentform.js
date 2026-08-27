// View script for the equipment create/edit form (views/equipmentform.blade.php):
// saves an equipment item via the objects/equipment API endpoints and handles
// upload/replacement/deletion of the instruction manual file.

// Form submit: POST /api/objects/equipment on create or PUT /api/objects/equipment/{id} on edit
// (mode/id from Grocy.EditMode / Grocy.EditObjectId); a selected instruction manual is stored
// under a random-prefixed file name and uploaded to the "equipmentmanuals" file group
// after the object was saved (Grocy.Api.UploadFile)
$('#save-equipment-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("equipment-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#equipment-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("equipment-form");

	// A newly selected manual file gets a randomized, cleaned server side file name
	if ($("#instruction-manual")[0].files.length > 0)
	{
		jsonData.instruction_manual_file_name = RandomString() + CleanFileName($("#instruction-manual")[0].files[0].name);
	}

	if (Grocy.DeleteInstructionManualOnSave)
	{
		jsonData.instruction_manual_file_name = null;
	}

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/equipment', jsonData,
			function(result)
			{
				Grocy.EditObjectId = result.created_object_id;
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (jsonData.hasOwnProperty("instruction_manual_file_name") && !Grocy.DeleteInstructionManualOnSave)
					{
						Grocy.Api.UploadFile($("#instruction-manual")[0].files[0], 'equipmentmanuals', jsonData.instruction_manual_file_name,
							function(result)
							{
								window.location.href = U('/equipment');
							},
							function(xhr)
							{
								Grocy.FrontendHelpers.EndUiBusy("equipment-form");
								Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}
					else
					{
						window.location.href = U('/equipment');
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("equipment-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		// Edit mode: delete the old manual file first when its removal was requested
		if (Grocy.DeleteInstructionManualOnSave)
		{
			Grocy.Api.DeleteFile(Grocy.InstructionManualFileNameName, 'equipmentmanuals',
				function(result)
				{
					// Nothing to do
				},
				function(xhr)
				{
					Grocy.FrontendHelpers.EndUiBusy("equipment-form");
					Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		};

		Grocy.Api.Put('objects/equipment/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (jsonData.hasOwnProperty("instruction_manual_file_name") && !Grocy.DeleteInstructionManualOnSave)
					{
						Grocy.Api.UploadFile($("#instruction-manual")[0].files[0], 'equipmentmanuals', jsonData.instruction_manual_file_name,
							function(result)
							{
								window.location.href = U('/equipment');
							},
							function(xhr)
							{
								Grocy.FrontendHelpers.EndUiBusy("equipment-form");
								Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}
					else
					{
						window.location.href = U('/equipment');
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("equipment-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validate on any input; Enter submits the form when valid
$('#equipment-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('equipment-form');
});

$('#equipment-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('equipment-form'))
		{
			return false;
		}
		else
		{
			$('#save-equipment-button').click();
		}
	}
});

// "Delete manual" only flags the file for removal on save (Grocy.DeleteInstructionManualOnSave)
// and updates the hints - the actual delete happens in the submit handler above
Grocy.DeleteInstructionManualOnSave = false;
$('#delete-current-instruction-manual-button').on('click', function(e)
{
	Grocy.DeleteInstructionManualOnSave = true;
	$("#current-equipment-instruction-manual").addClass("d-none");
	$("#delete-current-instruction-manual-on-save-hint").removeClass("d-none");
	$("#delete-current-instruction-manual-button").addClass("disabled");
	$("#instruction-manual-label").addClass("d-none");
	$("#instruction-manual-label-none").removeClass("d-none");
});
ResizeResponsiveEmbeds();

// Initial state: load userfield values, validate once and focus the name field
Grocy.Components.UserfieldsForm.Load();
Grocy.FrontendHelpers.ValidateForm('equipment-form');
setTimeout(function()
{
	$('#name').focus();
}, Grocy.FormFocusDelay);

// Selecting a new manual file cancels a pending "delete manual" request
$("#instruction-manual").on("change", function(e)
{
	$("#instruction-manual-label").removeClass("d-none");
	$("#instruction-manual-label-none").addClass("d-none");
	$("#delete-current-instruction-manual-on-save-hint").addClass("d-none");
	$("#current-instruction-manuale").addClass("d-none");
	Grocy.DeleteProductPictureOnSave = false;
});
