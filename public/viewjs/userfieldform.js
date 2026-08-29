// Powers the custom userfield create/edit modal form (userfieldform.blade.php) -
// defines a custom field attached to a userentity/built-in entity. Victual.EditMode
// ('create'/'edit') and Victual.EditObjectId select POST vs PUT.

// Validates and submits the form, then either postMessages the parent (embedded mode)
// to reload, or navigates back to the userfields list (scoped to the entity, if the
// "entity" URI param was set, i.e. opened from that entity's field list)
$('#save-userfield-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("userfield-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#userfield-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("userfield-form");

	var redirectUrl = U("/userfields");
	if (GetUriParam("entity"))
	{
		redirectUrl = U("/userfields?entity=" + GetUriParam("entity"));
	}

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/userfields', jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
				}
				else
				{
					window.location.href = redirectUrl;
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("userfield-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/userfields/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
				}
				else
				{
					window.location.href = redirectUrl;
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("userfield-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validates on every keystroke / select change
$('#userfield-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('userfield-form');
});

$('#userfield-form select').change(function(event)
{
	Victual.FrontendHelpers.ValidateForm('userfield-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#userfield-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('userfield-form'))
		{
			return false;
		}
		else
		{
			$('#save-userfield-button').click();
		}
	}
});

// Field type dropdown drives the rest of the form: shows the "config" textarea (list of
// preset values) only for preset-list/preset-checklist types, and swaps in the matching
// default-value input for the selected type (each type has its own hidden .userfield-type-*
// default-value group in the markup)
$("#type").on("change", function(e)
{
	var value = $(this).val();

	if (value === "preset-list" || value === "preset-checklist")
	{
		$("#config").parent().removeClass("d-none");
		$("#config-hint").text(__t("A predefined list of values, one per line"));
	}
	else
	{
		$("#config").parent().addClass("d-none");
		$("#config-hint").text("");
	}

	$("#default-value-group").addClass("d-none");
	$("#default-value-group.userfield-type-" + value).removeClass("d-none");
});

// Pre-select the entity when opened scoped to one (and focus the name field instead of
// the entity picker in that case)
if (GetUriParam("entity"))
{
	$("#entity").val(GetUriParam("entity"));
	$("#entity").trigger("change");
	setTimeout(function()
	{
		$('#name').focus();
	}, Victual.FormFocusDelay);
}
else
{
	setTimeout(function()
	{
		$('#entity').focus();
	}, Victual.FormFocusDelay);
}

$("#type").trigger("change");
Victual.FrontendHelpers.ValidateForm('userfield-form');
