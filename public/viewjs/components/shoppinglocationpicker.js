// Implements the ShoppingLocationPicker widget (views/components/shoppinglocationpicker.blade.php):
// a combobox of shopping locations (stores), driven by a hidden #shopping_location_id select
// plus #shopping_location_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Grocy.Components.ShoppingLocationPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#shopping_location_id) */
Grocy.Components.ShoppingLocationPicker.GetPicker = function ()
{
	return $('#shopping_location_id');
}

/** @returns {jQuery} The visible text input of the combobox (#shopping_location_id_text_input) */
Grocy.Components.ShoppingLocationPicker.GetInputElement = function ()
{
	return $('#shopping_location_id_text_input');
}

/** @returns {string} The currently selected shopping location id */
Grocy.Components.ShoppingLocationPicker.GetValue = function ()
{
	return $('#shopping_location_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Grocy.Components.ShoppingLocationPicker.SetValue = function (value)
{
	Grocy.Components.ShoppingLocationPicker.GetInputElement().val(value);
	Grocy.Components.ShoppingLocationPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given shopping location id directly, refreshing the combobox display */
Grocy.Components.ShoppingLocationPicker.SetId = function (value)
{
	Grocy.Components.ShoppingLocationPicker.GetPicker().val(value);
	Grocy.Components.ShoppingLocationPicker.GetPicker().data('combobox').refresh();
	Grocy.Components.ShoppingLocationPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Grocy.Components.ShoppingLocationPicker.Clear = function ()
{
	Grocy.Components.ShoppingLocationPicker.SetValue('');
	Grocy.Components.ShoppingLocationPicker.SetId(null);
}

$(".shopping-location-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by shopping location name (from the template's data-prefill-by-name attribute on the
// wrapper), then focus the configured next input (data-next-input-selector)
var prefillByName = Grocy.Components.ShoppingLocationPicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#shopping_location_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#shopping_location_id').val(possibleOptionElement.val());
		$('#shopping_location_id').data('combobox').refresh();
		$('#shopping_location_id').trigger('change');

		var nextInputElement = $(Grocy.Components.ShoppingLocationPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by shopping location id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Grocy.Components.ShoppingLocationPicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#shopping_location_id').val(prefillById);
	$('#shopping_location_id').data('combobox').refresh();
	$('#shopping_location_id').trigger('change');

	var nextInputElement = $(Grocy.Components.ShoppingLocationPicker.GetPicker().parent().data('next-input-selector').toString());
	nextInputElement.focus();
}
