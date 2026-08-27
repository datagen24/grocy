// View script for the meal plan section create/edit form (views/mealplansectionform.blade.php):
// saves a meal plan section via the objects/meal_plan_sections API endpoints.

// Form submit: POST /api/objects/meal_plan_sections on create or PUT .../{id} on edit
// (mode/id from Grocy.EditMode / Grocy.EditObjectId); when embedded in a dialog iframe
// (?embedded), the parent window is told to reload instead of navigating
$('#save-mealplansection-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("mealplansection-form", true))
	{
		return;
	}

	var jsonData = $('#mealplansection-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("mealplansection-form");

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/meal_plan_sections', jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
				}
				else
				{
					window.location.href = U('/mealplansections');
				}
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("mealplansection-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Api.Put('objects/meal_plan_sections/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
				}
				else
				{
					window.location.href = U('/mealplansections');
				}
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("mealplansection-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validate on any input; Enter submits the form when valid
$('#mealplansection-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('mealplansection-form');
});

$('#mealplansection-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('mealplansection-form'))
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
Grocy.FrontendHelpers.ValidateForm('mealplansection-form');
setTimeout(function()
{
	$('#name').focus();
}, Grocy.FormFocusDelay);
