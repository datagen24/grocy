// Powers the shopping location create/edit modal form (shoppinglocationform.blade.php).
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT.

// Validates and submits the form, saves userfields, then either postMessages the
// parent (embedded mode) to reload, or navigates back to the shopping locations list
$('#save-shopping-location-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("shoppinglocation-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#shoppinglocation-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("shoppinglocation-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/shopping_locations', jsonData,
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
						window.location.href = U('/shoppinglocations');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglocation-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/shopping_locations/' + Victual.EditObjectId, jsonData,
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
						window.location.href = U('/shoppinglocations');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglocation-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validates on every keystroke
$('#shoppinglocation-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('shoppinglocation-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#shoppinglocation-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('shoppinglocation-form'))
		{
			return false;
		}
		else
		{
			$('#save-shopping-location-button').click();
		}
	}
});

// Initial setup: load userfields, focus the name field, run initial validation
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm('shoppinglocation-form');
