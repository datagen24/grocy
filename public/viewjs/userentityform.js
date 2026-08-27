// Powers the user entity create/edit modal form (userentityform.blade.php).
// Grocy.EditMode ('create'/'edit') and Grocy.EditObjectId select POST vs PUT. Note:
// unlike most other forms here, this one has no userfields (a user entity IS the
// definition userfields attach to, so it can't itself carry them).

// Validates and submits the form, then either postMessages the parent (embedded mode)
// to reload, or navigates back to the user entities list
$('#save-userentity-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("userentity-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#userentity-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("userentity-form");

	var redirectUrl = U("/userentities");

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/userentities', jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
				}
				else
				{
					window.location.href = redirectUrl;
				}
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("userentity-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Api.Put('objects/userentities/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				if (GetUriParam("embedded") !== undefined)
				{
					window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
				}
				else
				{
					window.location.href = redirectUrl;
				}
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("userentity-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live-validates on every keystroke / select change
$('#userentity-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('userentity-form');
});

$('#userentity-form select').change(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('userentity-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#userentity-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('userentity-form'))
		{
			return false;
		}
		else
		{
			$('#save-userentity-button').click();
		}
	}
});

$("#show_in_sidebar_menu").on("click", function()
{
	if (this.checked)
	{
		$("#icon_css_class").removeAttr("disabled");
	}
	else
	{
		$("#icon_css_class").attr("disabled", "");
	}
});

Grocy.FrontendHelpers.ValidateForm('userentity-form');
setTimeout(function()
{
	$('#name').focus();
}, Grocy.FormFocusDelay);

// Click twice to trigger on-click but not change the actual checked state
$("#show_in_sidebar_menu").click();
$("#show_in_sidebar_menu").click();
