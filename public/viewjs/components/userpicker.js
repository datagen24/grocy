// Implements the UserPicker widget (views/components/userpicker.blade.php): a combobox of
// users, driven by a hidden #user_id select plus #user_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Victual.Components.UserPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#user_id) */
Victual.Components.UserPicker.GetPicker = function ()
{
	return $('#user_id');
}

/** @returns {jQuery} The visible text input of the combobox (#user_id_text_input) */
Victual.Components.UserPicker.GetInputElement = function ()
{
	return $('#user_id_text_input');
}

/** @returns {string} The currently selected user id */
Victual.Components.UserPicker.GetValue = function ()
{
	return $('#user_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Victual.Components.UserPicker.SetValue = function (value)
{
	Victual.Components.UserPicker.GetInputElement().val(value);
	Victual.Components.UserPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given user id directly, refreshing the combobox display */
Victual.Components.UserPicker.SetId = function (value)
{
	Victual.Components.UserPicker.GetPicker().val(value);
	Victual.Components.UserPicker.GetPicker().data('combobox').refresh();
	Victual.Components.UserPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Victual.Components.UserPicker.Clear = function ()
{
	Victual.Components.UserPicker.SetValue('');
	Victual.Components.UserPicker.SetId(null);
}

$(".user-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by username (from the template's data-prefill-by-username attribute on the wrapper),
// matched against additional-searchdata first, falling back to a name-contains match
var prefillUser = Victual.Components.UserPicker.GetPicker().parent().data('prefill-by-username').toString();
if (typeof prefillUser !== "undefined")
{
	var possibleOptionElement = $("#user_id option[data-additional-searchdata*=\"" + prefillUser + "\"]").first();
	if (possibleOptionElement.length === 0)
	{
		possibleOptionElement = $("#user_id option:contains(\"" + prefillUser + "\")").first();
	}

	if (possibleOptionElement.length > 0)
	{
		$('#user_id').val(possibleOptionElement.val());
		$('#user_id').data('combobox').refresh();
		$('#user_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.UserPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by user id (from the template's data-prefill-by-user-id attribute on the wrapper)
var prefillUserId = Victual.Components.UserPicker.GetPicker().parent().data('prefill-by-user-id').toString();
if (typeof prefillUserId !== "undefined")
{
	var possibleOptionElement = $("#user_id option[value='" + prefillUserId + "']").first();
	if (possibleOptionElement.length > 0)
	{
		$('#user_id').val(possibleOptionElement.val());
		$('#user_id').data('combobox').refresh();
		$('#user_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.UserPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}
