// Powers the user create/edit form (userform.blade.php). Grocy.EditMode ('create'/'edit')
// and Grocy.EditObjectId select POST vs PUT. Handles the optional profile picture upload/
// removal and the password change checkbox in addition to the regular form fields.

/**
 * Second step of saving a user: after the user object itself was created/updated, saves
 * userfields and then either uploads the newly chosen picture file (if any, and it wasn't
 * also flagged for deletion) or just redirects to the users list.
 * @param {object} result - API response from the create/update call (used for created_object_id)
 * @param {object} jsonData - the serialized form data (used for picture_file_name)
 */
function SaveUserPicture(result, jsonData)
{
	var userId = Grocy.EditObjectId || result.created_object_id;
	Grocy.Components.UserfieldsForm.Save(() =>
	{
		if (jsonData.hasOwnProperty("picture_file_name") && !Grocy.DeleteUserPictureOnSave)
		{
			Grocy.Api.UploadFile($("#user-picture")[0].files[0], 'userpictures', jsonData.picture_file_name,
				(result) =>
				{
					window.location.href = U('/users');
				},
				(xhr) =>
				{
					Grocy.FrontendHelpers.EndUiBusy("user-form");
					Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}
		else
		{
			window.location.href = U('/users');
		}
	});
}

// Validates and submits the form: generates a randomized/sanitized file name for a newly
// chosen picture, base64-encodes the password (password_base64, as the API expects) and
// drops the raw password/confirm/change_password fields, then creates or updates the user
// and hands off to SaveUserPicture() for the picture upload and final redirect
$('#save-user-button').on('click', function (e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("user-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#user-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("user-form");

	if ($("#user-picture")[0].files.length > 0)
	{
		jsonData.picture_file_name = RandomString() + CleanFileName($("#user-picture")[0].files[0].name);
	}

	jsonData.password_base64 = btoa(jsonData.password);
	delete jsonData.password;
	delete jsonData.password_confirm;
	delete jsonData.change_password;

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('users', jsonData,
			(result) => SaveUserPicture(result, jsonData),
			function (xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("user-form");
				console.error(xhr);
			}
		);
	}
	else
	{
		// If the existing picture was flagged for removal, delete the old file server-side
		if (Grocy.DeleteUserPictureOnSave)
		{
			jsonData.picture_file_name = null;

			Grocy.Api.DeleteFile(Grocy.UserPictureFileName, 'userpictures',
				function (result)
				{
					// Nothing to do
				},
				function (xhr)
				{
					Grocy.FrontendHelpers.EndUiBusy("user-form");
					Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}

		Grocy.Api.Put('users/' + Grocy.EditObjectId, jsonData,
			(result) => SaveUserPicture(result, jsonData),
			function (xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("user-form");
				console.error(xhr);
			}
		);
	}
});

// Live-validates on every keystroke, plus a custom-validity check that the password
// confirmation matches the password
$('#user-form input').keyup(function (event)
{
	var element = document.getElementById("password_confirm");
	if ($("#password").val() !== $("#password_confirm").val())
	{
		element.setCustomValidity("error");
	}
	else
	{
		element.setCustomValidity("");
	}

	Grocy.FrontendHelpers.ValidateForm('user-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#user-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('user-form'))
		{
			return false;
		}
		else
		{
			$('#save-user-button').click();
		}
	}
});

// A newly chosen picture file replaces the "current picture" preview with the
// file-chosen label. Note: this sets Grocy.DeleteUserePictureOnSave (misspelled, "Usere")
// rather than the Grocy.DeleteUserPictureOnSave flag actually checked on submit further
// below - so picking a new file after clicking "delete current picture" does not clear
// the pending deletion flag as apparently intended.
$("#user-picture").on("change", function (e)
{
	$("#user-picture-label").removeClass("d-none");
	$("#user-picture-label-none").addClass("d-none");
	$("#delete-current-user-picture-on-save-hint").addClass("d-none");
	$("#current-user-picture").addClass("d-none");
	Grocy.DeleteUserePictureOnSave = false;
});

Grocy.DeleteUserPictureOnSave = false;
// Flags the existing picture for deletion on save (actual deletion happens in the submit
// handler above)
$("#delete-current-user-picture-button").on("click", function (e)
{
	Grocy.DeleteUserPictureOnSave = true;
	$("#current-user-picture").addClass("d-none");
	$("#delete-current-user-picture-on-save-hint").removeClass("d-none");
	$("#user-picture-label").addClass("d-none");
	$("#user-picture-label-none").removeClass("d-none");
});

// Enables the password fields only once "change password" is checked
$("#change_password").click(function ()
{
	$("#password").attr("disabled", !this.checked);
	$("#password_confirm").attr("disabled", !this.checked);

	setTimeout(function ()
	{
		$("#password").focus();
	}, Grocy.FormFocusDelay);
});

// If opened with the "changepw" URI param, immediately reveal the password fields;
// otherwise focus the username field
if (GetUriParam("changepw") === "true")
{
	$("#change_password").click();
}
else
{
	setTimeout(function ()
	{
		$('#username').focus();
	}, Grocy.FormFocusDelay);
}

// Initial setup: load userfields, run initial validation
Grocy.Components.UserfieldsForm.Load();
Grocy.FrontendHelpers.ValidateForm('user-form');
