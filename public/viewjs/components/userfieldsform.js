// Implements the UserfieldsForm widget (views/components/userfieldsform.blade.php,
// userfields_thead.blade.php, userfields_tbody.blade.php): renders/persists the custom
// "userfields" defined for an entity (text/checkbox/date/datetime/file/link/multi-select
// inputs with class "userfield-input") against Victual.EditObjectId.
// Public API: Save(success, error), Load(), Clear() - all are no-ops when no #userfields-form
// is present on the page. Consumers call these alongside their own object save/load/clear flow.
Victual.Components.UserfieldsForm = {};

/**
 * Persists every changed ("is-dirty") userfield input via PUT userfields/{entity}/{Victual.EditObjectId},
 * one field at a time. File-type fields additionally upload the new file and/or delete the old
 * one (Victual.Api.UploadFile/DeleteFile against the "userfiles" group) before/after the PUT.
 * @param {function} [success] Called once after the last field has saved successfully.
 * @param {function} [error] Called once if the last field's save (or its file operation) fails.
 */
Victual.Components.UserfieldsForm.Save = function (success, error)
{
	if (!$("#userfields-form").length)
	{
		if (success)
		{
			success();
		}

		return;
	}

	var editedUserfieldInputs = $("#userfields-form .userfield-input.is-dirty").not("div");

	if (!editedUserfieldInputs.length)
	{
		if (success)
		{
			success();
		}

		return;
	}

	editedUserfieldInputs.each(function (index, item)
	{
		var jsonData = {};
		var input = $(this);
		var fieldName = input.attr("data-userfield-name");
		var fieldValue = input.val();

		if (input.attr("type") == "checkbox")
		{
			jsonData[fieldName] = "0";
			if (input.is(":checked"))
			{
				jsonData[fieldName] = "1";
			}
		}
		else if (input.attr("type") == "file")
		{
			if (input.hasAttr("data-old-file"))
			{
				var oldFile = input.attr("data-old-file");
				if (oldFile)
				{
					jsonData[fieldName] = "";
				}
			}

			if (input[0].files.length > 0)
			{
				// Files service requires an extension
				var newFile = RandomString() + '.' + CleanFileName(input[0].files[0].name.split('.').reverse()[0]);
				jsonData[fieldName] = btoa(newFile) + '_' + btoa(CleanFileName(input[0].files[0].name));

			}
		}
		else if ($(this).hasAttr("multiple"))
		{
			jsonData[fieldName] = $(this).val().join(",");
		}
		else
		{
			jsonData[fieldName] = fieldValue;
		}

		Victual.Api.Put('userfields/' + $("#userfields-form").data("entity") + '/' + Victual.EditObjectId, jsonData,
			function (result)
			{
				// Depending on which file-related state was collected above, follow up the field
				// value save with the matching file upload and/or delete against 'userfiles'
				if (typeof newFile !== 'undefined' && typeof oldFile !== 'undefined') // Delete and Upload
				{
					Victual.Api.DeleteFile(oldFile, 'userfiles',
						function (result)
						{
							Victual.Api.UploadFile(input[0].files[0], 'userfiles', newFile,
								function (result2)
								{
									if (success && index === editedUserfieldInputs.length - 1) // Last item
									{
										success();
									}
								},
								function (xhr)
								{
									Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
									if (error && index === editedUserfieldInputs.length - 1) // Last item
									{
										error();
									}
								}
							);
						},
						function (xhr)
						{
							Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							if (error && index === editedUserfieldInputs.length - 1) // Last item
							{
								error();
							}
						}
					);
				}
				else if (typeof newFile !== 'undefined') // Upload only
				{
					Victual.Api.UploadFile(input[0].files[0], 'userfiles', newFile,
						function (result2)
						{
							if (success && index === editedUserfieldInputs.length - 1) // Last item
							{
								success();
							}
						},
						function (xhr)
						{
							Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							if (error && index === editedUserfieldInputs.length - 1) // Last item
							{
								error();
							}
						}
					);
				}
				else if (typeof oldFile !== 'undefined') // Delete only
				{
					Victual.Api.DeleteFile(oldFile, 'userfiles',
						function (result)
						{
							if (success && index === editedUserfieldInputs.length - 1) // Last item
							{
								success();
							}
						},
						function (xhr)
						{
							Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							if (error && index === editedUserfieldInputs.length - 1) // Last item
							{
								error();
							}
						}
					);
				}
				else // Nothing else to do
				{
					if (success && index === editedUserfieldInputs.length - 1) // Last item
					{
						success();
					}
				}
			},
			function (xhr)
			{
				if (error && index === editedUserfieldInputs.length - 1) // Last item
				{
					error();
				}
			}
		);
	});
}

/**
 * Populates the userfield inputs: when no Victual.EditObjectId is set (creating a new object),
 * applies each userfield's configured default value (GET objects/userfields); otherwise loads
 * the object's actual stored values (GET userfields/{entity}/{Victual.EditObjectId}) and renders
 * them per input type (checkbox/file/multi-select/link/plain).
 */
Victual.Components.UserfieldsForm.Load = function ()
{
	if (!$("#userfields-form").length)
	{
		return;
	}

	if (typeof Victual.EditObjectId == "undefined")
	{
		// Init fields by configured default values

		Victual.Api.Get("objects/userfields?query[]=entity=" + $("#userfields-form").data("entity"),
			function (result)
			{
				$.each(result, function (key, userfield)
				{
					var input = $(".userfield-input[data-userfield-name='" + userfield.name + "']");

					if (userfield.type == "datetime" && userfield.default_value == "now")
					{
						input.val(moment().format("YYYY-MM-DD HH:mm:ss"));
					}
					else if (userfield.type == "date" && userfield.default_value == "now")
					{
						input.val(moment().format("YYYY-MM-DD"));
					}
					else if (userfield.type == "checkbox" && userfield.input_required == 1)
					{
						input.prop("indeterminate", true);
						input.on("change", function ()
						{
							input.removeAttr("required");
						});
					}
				});

				$("form").each(function ()
				{
					Victual.FrontendHelpers.ValidateForm(this.id);
				});
			}
		);
	}
	else
	{
		// Load object field values

		Victual.Api.Get('userfields/' + $("#userfields-form").data("entity") + '/' + Victual.EditObjectId,
			function (result)
			{
				$.each(result, function (key, value)
				{
					var input = $(".userfield-input[data-userfield-name='" + key + "']");

					if (input.attr("type") == "checkbox")
					{
						// The required attribute for checkboxes is only relevant when creating objects
						input.removeAttr("required");
					}

					if (input.attr("type") == "checkbox" && value == 1)
					{
						input.prop("checked", true);
					}
					else if (input.hasAttr("multiple"))
					{
						if (value)
						{
							input.val(value.split(","));
						}

						$(".selectpicker").selectpicker("render");
					}
					else if (input.attr('type') == "file")
					{
						if (value)
						{
							var fileName = atob(value.split('_')[1]);
							var fileSrc = atob(value.split('_')[0]);
							var formGroup = input.parent().parent().parent();

							formGroup.find("label.custom-file-label").text(fileName);
							formGroup.find(".userfield-file-show").attr('href', U('/api/files/userfiles/' + value));
							formGroup.find('.userfield-file-show').removeClass('d-none');
							formGroup.find('img.userfield-current-file').attr('src', U('/api/files/userfiles/' + value + '?force_serve_as=picture&best_fit_width=250&best_fit_height=250'));

							formGroup.find('.userfield-file-delete').click(
								function ()
								{
									formGroup.find("label.custom-file-label").text(__t("No file selected"));
									formGroup.find(".userfield-file-show").addClass('d-none');
									input.attr('data-old-file', fileSrc);
									input.addClass("is-dirty");
								}
							);

							input.on("change", function (e)
							{
								formGroup.find(".userfield-file-show").addClass('d-none');
							});
						}
					}
					else if (input.attr("data-userfield-type") == "link")
					{
						if (value)
						{
							var data = JSON.parse(value);

							var formRow = input.parent().parent();
							formRow.find(".userfield-link-title").val(data.title);
							formRow.find(".userfield-link-link").val(data.link);

							input.val(value);
						}
					}
					else
					{
						input.val(value);
					}
				});

				$("form").each(function ()
				{
					Victual.FrontendHelpers.ValidateForm(this.id);
				});
			}
		);
	}
}

/**
 * Resets every userfield input to its empty state (unchecked/blank/no file), fetching the
 * entity's userfield definitions (GET objects/userfields) to know which inputs exist and their types.
 */
Victual.Components.UserfieldsForm.Clear = function ()
{
	if (!$("#userfields-form").length)
	{
		return;
	}

	Victual.Api.Get('objects/userfields?query[]=entity=' + $("#userfields-form").data("entity"),
		function (result)
		{
			$.each(result, function (key, userfield)
			{
				var input = $(".userfield-input[data-userfield-name='" + userfield.name + "']");

				if (input.attr("type") == "checkbox")
				{
					input.prop("checked", false);
				}
				else if (input.hasAttr("multiple"))
				{
					input.val("");
					$(".selectpicker").selectpicker("render");
				}
				else if (input.attr('type') == "file")
				{
					var formGroup = input.parent().parent().parent();

					formGroup.find("label.custom-file-label").text("");
					formGroup.find(".userfield-file-show").attr('href', U('/api/files/userfiles/' + value));
					formGroup.find('.userfield-file-show').removeClass('d-none');
					formGroup.find('img.userfield-current-file')
						.attr('src', U('/api/files/userfiles/' + value + '?force_serve_as=picture&best_fit_width=250&best_fit_height=250'));

					formGroup.find('.userfield-file-delete').click(
						function ()
						{
							formGroup.find("label.custom-file-label").text(__t("No file selected"));
							formGroup.find(".userfield-file-show").addClass('d-none');
							input.attr('data-old-file', "");
						}
					);

					input.on("change", function (e)
					{
						formGroup.find(".userfield-file-show").addClass('d-none');
					});
				}
				else if (input.attr("data-userfield-type") == "link")
				{
					var formRow = input.parent().parent();
					formRow.find(".userfield-link-title").val(data.title);
					formRow.find(".userfield-link-link").val(data.link);

					input.val("");
				}
				else
				{
					input.val("");
				}
			});

			$("form").each(function ()
			{
				Victual.FrontendHelpers.ValidateForm(this.id);
			});
		}
	);
}

// "link" type userfields are backed by two visible title/link inputs; keeps the hidden
// userfield-input's JSON value (and dirty state) in sync as either is typed
$(".userfield-link").keyup(function (e)
{
	var formRow = $(this).parent().parent();
	var title = formRow.find(".userfield-link-title").val();
	var link = formRow.find(".userfield-link-link").val();

	var value = {
		"title": title,
		"link": link
	};

	formRow.find(".userfield-input").val(JSON.stringify(value)).addClass("is-dirty");
});

// Re-validates every form on the page whenever a userfield input changes
$(".userfield-input").change(function (e)
{
	$("form").each(function ()
	{
		Victual.FrontendHelpers.ValidateForm(this.id);
	});
});

// Bootstrap-select fires its own "changed.bs.select" event instead of a native "change";
// mark the field dirty so Save() picks it up
$(".userfield-input.selectpicker").on("changed.bs.select", function ()
{
	$(this).addClass("is-dirty");
});
