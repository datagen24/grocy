// Powers the create/edit form for a single record ("userobject") of a custom user
// entity (userobjectform.blade.php). A userobject has no fields of its own beyond its
// entity link - all visible inputs are userfields, so the form body itself is just the
// (relabeled) userfields form; Victual.EditObjectParentId/-Name identify the owning entity.

// Creates/updates the bare userobject (linking it to its parent entity), then saves the
// userfields that carry its actual data, then either postMessages the parent (embedded
// mode) to reload, or navigates back to that entity's object list
$('#save-userobject-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("userobject-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = {};
	jsonData.userentity_id = Victual.EditObjectParentId;

	Victual.FrontendHelpers.BeginUiBusy("userobject-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/userobjects', jsonData,
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
						window.location.href = U('/userobjects/' + Victual.EditObjectParentName);
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("userobject-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/userobjects/' + Victual.EditObjectId, jsonData,
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
						window.location.href = U('/userobjects/' + Victual.EditObjectParentName);
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("userobject-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Load userfield values, then strip the userfields form's usual "boxed section" styling
// and heading since here it IS the whole form rather than an addendum to one
Victual.Components.UserfieldsForm.Load();
$("#userfields-form").removeClass("border").removeClass("border-info").removeClass("p-2").find("h2").addClass("d-none");

setTimeout(function()
{
	$(".userfield-input").first().focus();
}, Victual.FormFocusDelay);
