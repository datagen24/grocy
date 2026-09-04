// Implements the RecipePicker widget (views/components/recipepicker.blade.php): a combobox of
// recipes, driven by a hidden #recipe_id select plus #recipe_id_text_input, which also resolves
// scanned/typed Grocycodes (grcy:r:<id>) and "Victual.BarcodeScanned" events targeted at it.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Victual.Components.RecipePicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#recipe_id) */
Victual.Components.RecipePicker.GetPicker = function ()
{
	return $('#recipe_id');
}

/** @returns {jQuery} The visible text input of the combobox (#recipe_id_text_input) */
Victual.Components.RecipePicker.GetInputElement = function ()
{
	return $('#recipe_id_text_input');
}

/** @returns {string} The currently selected recipe id */
Victual.Components.RecipePicker.GetValue = function ()
{
	return $('#recipe_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Victual.Components.RecipePicker.SetValue = function (value)
{
	Victual.Components.RecipePicker.GetInputElement().val(value);
	Victual.Components.RecipePicker.GetInputElement().trigger('change');
}

/** Selects the option with the given recipe id directly, refreshing the combobox display */
Victual.Components.RecipePicker.SetId = function (value)
{
	Victual.Components.RecipePicker.GetPicker().val(value);
	Victual.Components.RecipePicker.GetPicker().data('combobox').refresh();
	Victual.Components.RecipePicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Victual.Components.RecipePicker.Clear = function ()
{
	Victual.Components.RecipePicker.SetValue('');
	Victual.Components.RecipePicker.SetId(null);
}

$(".recipe-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by recipe name (from the template's data-prefill-by-name attribute on the wrapper),
// then focus the configured next input (data-next-input-selector)
var prefillByName = Victual.Components.RecipePicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#recipe_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#recipe_id').val(possibleOptionElement.val());
		$('#recipe_id').data('combobox').refresh();
		$('#recipe_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.RecipePicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by recipe id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Victual.Components.RecipePicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#recipe_id').val(prefillById);
	$('#recipe_id').data('combobox').refresh();
	$('#recipe_id').trigger('change');

	var nextInputElement = $(document).find(Victual.Components.RecipePicker.GetPicker().parent().data('next-input-selector').toString());
	nextInputElement.focus();
}

// Resolves a typed Grocycode (grcy:r:<id>) on blur, selecting the matching recipe option or
// clearing the field if it doesn't resolve to one
$('#recipe_id_text_input').on('blur', function (e)
{
	if ($('#recipe_id').hasClass("combobox-menu-visible"))
	{
		return;
	}

	var input = $('#recipe_id_text_input').val().toString();
	var possibleOptionElement = [];

	// Grocycode handling
	if (input.startsWith("grcy"))
	{
		var gc = input.split(":");
		if (gc[1] == "r")
		{
			possibleOptionElement = $("#recipe_id option[value=\"" + gc[2] + "\"]").first();
		}

		if (possibleOptionElement.length > 0)
		{
			$('#recipe_id').val(possibleOptionElement.val());
			$('#recipe_id').data('combobox').refresh();
			$('#recipe_id').trigger('change');
		}
		else
		{
			$('#recipe_id').val(null);
			$('#recipe_id_text_input').val("");
			$('#recipe_id').data('combobox').refresh();
			$('#recipe_id').trigger('change');
		}
	}
});

// Handles a scanned barcode/Grocycode targeted at the recipe picker (from CameraBarcodeScanner
// or an external scanner), routing it into the text input as if typed, which then triggers blur handling
$(document).on("Victual.BarcodeScanned", function (e, barcode, target)
{
	if (!(target == "@recipepicker" || target == "undefined" || target == undefined)) // Default target
	{
		return;
	}

	// Don't know why the blur event does not fire immediately ... this works...
	Victual.Components.RecipePicker.GetInputElement().focusout();
	Victual.Components.RecipePicker.GetInputElement().focus();
	Victual.Components.RecipePicker.GetInputElement().blur();

	Victual.Components.RecipePicker.GetInputElement().val(barcode);

	setTimeout(function ()
	{
		Victual.Components.RecipePicker.GetInputElement().focusout();
		Victual.Components.RecipePicker.GetInputElement().focus();
		Victual.Components.RecipePicker.GetInputElement().blur();
	}, Victual.FormFocusDelay);
});
