// Implements the RecipePicker widget (views/components/recipepicker.blade.php): a combobox of
// recipes, driven by a hidden #recipe_id select plus #recipe_id_text_input, which also resolves
// scanned/typed Grocycodes (grcy:r:<id>) and "Grocy.BarcodeScanned" events targeted at it.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Grocy.Components.RecipePicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#recipe_id) */
Grocy.Components.RecipePicker.GetPicker = function ()
{
	return $('#recipe_id');
}

/** @returns {jQuery} The visible text input of the combobox (#recipe_id_text_input) */
Grocy.Components.RecipePicker.GetInputElement = function ()
{
	return $('#recipe_id_text_input');
}

/** @returns {string} The currently selected recipe id */
Grocy.Components.RecipePicker.GetValue = function ()
{
	return $('#recipe_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Grocy.Components.RecipePicker.SetValue = function (value)
{
	Grocy.Components.RecipePicker.GetInputElement().val(value);
	Grocy.Components.RecipePicker.GetInputElement().trigger('change');
}

/** Selects the option with the given recipe id directly, refreshing the combobox display */
Grocy.Components.RecipePicker.SetId = function (value)
{
	Grocy.Components.RecipePicker.GetPicker().val(value);
	Grocy.Components.RecipePicker.GetPicker().data('combobox').refresh();
	Grocy.Components.RecipePicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Grocy.Components.RecipePicker.Clear = function ()
{
	Grocy.Components.RecipePicker.SetValue('');
	Grocy.Components.RecipePicker.SetId(null);
}

$(".recipe-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by recipe name (from the template's data-prefill-by-name attribute on the wrapper),
// then focus the configured next input (data-next-input-selector)
var prefillByName = Grocy.Components.RecipePicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#recipe_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#recipe_id').val(possibleOptionElement.val());
		$('#recipe_id').data('combobox').refresh();
		$('#recipe_id').trigger('change');

		var nextInputElement = $(Grocy.Components.RecipePicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by recipe id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Grocy.Components.RecipePicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#recipe_id').val(prefillById);
	$('#recipe_id').data('combobox').refresh();
	$('#recipe_id').trigger('change');

	var nextInputElement = $(Grocy.Components.RecipePicker.GetPicker().parent().data('next-input-selector').toString());
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
$(document).on("Grocy.BarcodeScanned", function (e, barcode, target)
{
	if (!(target == "@recipepicker" || target == "undefined" || target == undefined)) // Default target
	{
		return;
	}

	// Don't know why the blur event does not fire immediately ... this works...
	Grocy.Components.RecipePicker.GetInputElement().focusout();
	Grocy.Components.RecipePicker.GetInputElement().focus();
	Grocy.Components.RecipePicker.GetInputElement().blur();

	Grocy.Components.RecipePicker.GetInputElement().val(barcode);

	setTimeout(function ()
	{
		Grocy.Components.RecipePicker.GetInputElement().focusout();
		Grocy.Components.RecipePicker.GetInputElement().focus();
		Grocy.Components.RecipePicker.GetInputElement().blur();
	}, Grocy.FormFocusDelay);
});
