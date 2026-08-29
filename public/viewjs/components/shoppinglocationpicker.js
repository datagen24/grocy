// Implements the ShoppingLocationPicker widget (views/components/shoppinglocationpicker.blade.php):
// a combobox of shopping locations (stores), driven by a hidden #shopping_location_id select
// plus #shopping_location_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Victual.Components.ShoppingLocationPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#shopping_location_id) */
Victual.Components.ShoppingLocationPicker.GetPicker = function ()
{
	return $('#shopping_location_id');
}

/** @returns {jQuery} The visible text input of the combobox (#shopping_location_id_text_input) */
Victual.Components.ShoppingLocationPicker.GetInputElement = function ()
{
	return $('#shopping_location_id_text_input');
}

/** @returns {string} The currently selected shopping location id */
Victual.Components.ShoppingLocationPicker.GetValue = function ()
{
	return $('#shopping_location_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Victual.Components.ShoppingLocationPicker.SetValue = function (value)
{
	Victual.Components.ShoppingLocationPicker.GetInputElement().val(value);
	Victual.Components.ShoppingLocationPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given shopping location id directly, refreshing the combobox display */
Victual.Components.ShoppingLocationPicker.SetId = function (value)
{
	Victual.Components.ShoppingLocationPicker.GetPicker().val(value);
	Victual.Components.ShoppingLocationPicker.GetPicker().data('combobox').refresh();
	Victual.Components.ShoppingLocationPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Victual.Components.ShoppingLocationPicker.Clear = function ()
{
	Victual.Components.ShoppingLocationPicker.SetValue('');
	Victual.Components.ShoppingLocationPicker.SetId(null);
}

$(".shopping-location-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by shopping location name (from the template's data-prefill-by-name attribute on the
// wrapper), then focus the configured next input (data-next-input-selector)
var prefillByName = Victual.Components.ShoppingLocationPicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#shopping_location_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#shopping_location_id').val(possibleOptionElement.val());
		$('#shopping_location_id').data('combobox').refresh();
		$('#shopping_location_id').trigger('change');

		var nextInputElement = $(Victual.Components.ShoppingLocationPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by shopping location id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Victual.Components.ShoppingLocationPicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#shopping_location_id').val(prefillById);
	$('#shopping_location_id').data('combobox').refresh();
	$('#shopping_location_id').trigger('change');

	var nextInputElement = $(Victual.Components.ShoppingLocationPicker.GetPicker().parent().data('next-input-selector').toString());
	nextInputElement.focus();
}
