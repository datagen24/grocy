// View script for the meal plan section create/edit form (views/mealplansectionform.blade.php):
// saves a meal plan section via the objects/meal_plan_sections API endpoints.

// Form submit: POST /api/objects/meal_plan_sections on create or PUT .../{id} on edit
// (mode/id from Victual.EditMode / Victual.EditObjectId); when embedded in a dialog iframe
// (?embedded), the parent window is told to reload instead of navigating
$('#save-mealplansection-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("mealplansection-form", true))
	{
		return;
	}

	var jsonData = $('#mealplansection-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("mealplansection-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/meal_plan_sections', jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
				}
				else
				{
					window.location.href = U('/mealplansections');
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("mealplansection-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/meal_plan_sections/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
				}
				else
				{
					window.location.href = U('/mealplansections');
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("mealplansection-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validate on any input; Enter submits the form when valid
$('#mealplansection-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('mealplansection-form');
});

$('#mealplansection-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('mealplansection-form'))
		{
			return false;
		}
		else
		{
			$('#save-mealplansections-button').click();
		}
	}
});

// Initial state: validate once and focus the name field
Victual.FrontendHelpers.ValidateForm('mealplansection-form');
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);
