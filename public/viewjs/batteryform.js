// View script for the battery create/edit form (views/batteryform.blade.php):
// saves via POST /api/objects/batteries (create) or PUT /api/objects/batteries/{id} (edit, using Victual.EditObjectId),
// including userfields; also handles grocycode label printing

// Form submit: validate, then create or update depending on Victual.EditMode; userfields are saved afterwards.
// When embedded (iframe), notifies the parent window instead of navigating back to /batteries.
$('#save-battery-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("battery-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#battery-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("battery-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/batteries', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
					}
					else
					{
						window.location.href = U('/batteries');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("battery-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/batteries/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
					}
					else
					{
						window.location.href = U('/batteries');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("battery-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live re-validation while typing
$('#battery-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('battery-form');
});

// Enter submits the form (when valid) instead of the browser default
$('#battery-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('battery-form'))
		{
			return false;
		}
		else
		{
			$('#save-battery-button').click();
		}
	}
});

// Print a battery grocycode label: GET /api/batteries/{id}/printlabel, then pass the label data to the configured label printer webhook
$(document).on('click', '.battery-grocycode-label-print', function(e)
{
	e.preventDefault();

	var batteryId = $(e.currentTarget).attr('data-battery-id');
	Victual.Api.Get('batteries/' + batteryId + '/printlabel', function(labelData)
	{
		if (Victual.Webhooks.labelprinter !== undefined)
		{
			Victual.FrontendHelpers.RunWebhook(Victual.Webhooks.labelprinter, labelData);
		}
	});
});

// Initial setup: load userfield values, focus the name input and validate once
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm('battery-form');
