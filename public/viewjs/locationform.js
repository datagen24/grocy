// View script for the location create/edit form (views/locationform.blade.php):
// saves a location via the objects/locations API endpoints, incl. userfields.

// Form submit: POST /api/objects/locations on create or PUT /api/objects/locations/{id} on edit
// (mode/id from Grocy.EditMode / Grocy.EditObjectId); when the form runs embedded in a dialog
// iframe (?embedded), the parent window is told to reload instead of navigating
$('#save-location-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("location-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#location-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("location-form");

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/locations', jsonData,
			function(result)
			{
				Grocy.EditObjectId = result.created_object_id;
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
					}
					else
					{
						window.location.href = U('/locations');
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("location-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Api.Put('objects/locations/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
					}
					else
					{
						window.location.href = U('/locations');
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("location-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validate on any input; Enter submits the form when valid
$('#location-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('location-form');
});

$('#location-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('location-form'))
		{
			return false;
		}
		else
		{
			$('#save-location-button').click();
		}
	}
});

// Initial state: load userfield values, validate once and focus the name field
Grocy.Components.UserfieldsForm.Load();
Grocy.FrontendHelpers.ValidateForm('location-form');
setTimeout(function()
{
	$('#name').focus();
}, Grocy.FormFocusDelay);
