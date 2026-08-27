// Powers the shopping list create/edit modal form (shoppinglistform.blade.php).
// Runs inside a modal iframe; Grocy.EditMode ('create'/'edit') and Grocy.EditObjectId
// are set by the server-rendered page to select POST vs PUT.

// Validates and submits the form, saves associated userfields, then notifies the parent
// window (shopping list page) via postMessage to refresh and close the modal
$('#save-shopping-list-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("shopping-list-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#shopping-list-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("shopping-list-form");

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/shopping_lists', jsonData,
			function(result)
			{
				Grocy.EditObjectId = result.created_object_id;
				Grocy.Components.UserfieldsForm.Save(function()
				{
					window.parent.postMessage(WindowMessageBag("ShoppingListChanged", result.created_object_id), Grocy.BaseUrl);
					window.parent.postMessage(WindowMessageBag("Ready"), Grocy.BaseUrl);
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("shopping-list-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Components.UserfieldsForm.Save(function()
		{
			Grocy.Api.Put('objects/shopping_lists/' + Grocy.EditObjectId, jsonData,
				function(result)
				{
					window.parent.postMessage(WindowMessageBag("ShoppingListChanged", Grocy.EditObjectId), Grocy.BaseUrl);
					window.parent.postMessage(WindowMessageBag("Ready"), Grocy.BaseUrl);
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
				},
				function(xhr)
				{
					Grocy.FrontendHelpers.EndUiBusy("shopping-list-form");
					Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		});
	}
});

// Live-validates on every keystroke
$('#shopping-list-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('shopping-list-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#shopping-list-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('shopping-list-form'))
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
Grocy.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Grocy.FormFocusDelay);
Grocy.FrontendHelpers.ValidateForm('shopping-list-form');
