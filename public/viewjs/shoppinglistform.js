// Powers the shopping list create/edit modal form (shoppinglistform.blade.php).
// Runs inside a modal iframe; Victual.EditMode ('create'/'edit') and Victual.EditObjectId
// are set by the server-rendered page to select POST vs PUT.

// Validates and submits the form, saves associated userfields, then notifies the parent
// window (shopping list page) via postMessage to refresh and close the modal
$('#save-shopping-list-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("shopping-list-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#shopping-list-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("shopping-list-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/shopping_lists', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					window.parent.postMessage(WindowMessageBag("ShoppingListChanged", result.created_object_id), Victual.BaseUrl);
					window.parent.postMessage(WindowMessageBag("Ready"), Victual.BaseUrl);
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shopping-list-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Components.UserfieldsForm.Save(function()
		{
			Victual.Api.Put('objects/shopping_lists/' + Victual.EditObjectId, jsonData,
				function(result)
				{
					window.parent.postMessage(WindowMessageBag("ShoppingListChanged", Victual.EditObjectId), Victual.BaseUrl);
					window.parent.postMessage(WindowMessageBag("Ready"), Victual.BaseUrl);
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("shopping-list-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		});
	}
});

// Live-validates on every keystroke
$('#shopping-list-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('shopping-list-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#shopping-list-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('shopping-list-form'))
		{
			return false;
		}
		else
		{
			$('#save-shopping-list-button').click();
		}
	}
});

// Initial setup: load userfields, focus the name field, run initial validation
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm('shopping-list-form');
