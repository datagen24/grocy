// Powers the product group create/edit form (views/productgroupform.blade.php), usually shown embedded in a modal:
// saves the product group via the objects/product_groups API and closes the modal.

// Form submit: POSTs objects/product_groups (create) or PUTs objects/product_groups/{id} (edit, id from Victual.EditObjectId),
// then saves userfields and asks the parent window to close the modal
$('#save-product-group-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("product-group-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#product-group-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("product-group-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/product_groups', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("product-group-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/product_groups/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save(function()
				{
					window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("product-group-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live validation while typing
$('#product-group-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('product-group-form');
});

// Enter submits the form (when valid)
$('#product-group-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('product-group-form'))
		{
			return false;
		}
		else
		{
			$('#save-product-group-button').click();
		}
	}
});

// Initial setup: load userfields, focus the name field, validate once
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm('product-group-form');
