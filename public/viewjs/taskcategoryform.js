// Powers the task category create/edit modal form (taskcategoryform.blade.php).
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT.

// Validates and submits the form, saves userfields, then either postMessages the
// parent (embedded mode) to reload, or navigates back to the task categories list
$('#save-task-category-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("task-category-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#task-category-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("task-category-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/task_categories', jsonData,
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
						window.location.href = U('/taskcategories');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("task-category-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/task_categories/' + Victual.EditObjectId, jsonData,
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
						window.location.href = U('/taskcategories');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("task-category-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validates on every keystroke
$('#task-category-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('task-category-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#task-category-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('task-category-form'))
		{
			return false;
		}
		else
		{
			$('#save-task-category-button').click();
		}
	}
});

// Initial setup: load userfields, focus the name field, run initial validation
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm('task-category-form');
