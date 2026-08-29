// Powers the task create/edit modal form (taskform.blade.php). Victual.EditMode
// ('create'/'edit') and Victual.EditObjectId select POST vs PUT. Two submit buttons share
// this handler: the regular save, and an "add another" variant (identified by the
// "add-another" class) that re-opens a fresh create form instead of closing.

// Validates and submits the form (renaming the picked user_id field to assigned_to_user_id
// as expected by the API, and reading the due date from the date picker component),
// saves userfields, then either postMessages the parent (embedded mode) to reload, or
// navigates back to the tasks list / a new task form
$('.save-task-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("task-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#task-form').serializeJSON();
	jsonData.assigned_to_user_id = jsonData.user_id;
	delete jsonData.user_id;
	jsonData.due_date = Victual.Components.DateTimePicker.GetValue();

	Victual.FrontendHelpers.BeginUiBusy("task-form");

	if (Victual.EditMode === 'create')
	{
		var addAnother = $(e.currentTarget).hasClass("add-another");

		Victual.Api.Post('objects/tasks', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						if (addAnother)
						{
							window.location.href = U('/task/new?embedded');
						}
						else
						{
							window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
						}
					}
					else
					{
						if (addAnother)
						{
							window.location.href = U('/task/new');
						}
						else
						{
							window.location.href = U('/tasks');
						}
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("task-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/tasks/' + Victual.EditObjectId, jsonData,
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
						window.location.href = U('/tasks');
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("task-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validates on every keystroke
$('#task-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('task-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#task-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('task-form'))
		{
			return false;
		}
		else
		{
			$('.save-task-button').first().click();
		}
	}
});

// Initial setup: load userfields, focus the name field, re-validate the due date, run initial validation
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
Victual.Components.DateTimePicker.GetInputElement().trigger('input');
Victual.FrontendHelpers.ValidateForm('task-form');
