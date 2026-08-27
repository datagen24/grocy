// Implements the LocationPicker widget (views/components/locationpicker.blade.php): a combobox
// of locations, driven by a hidden #location_id select plus #location_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Grocy.Components.LocationPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#location_id) */
Grocy.Components.LocationPicker.GetPicker = function ()
{
	return $('#location_id');
}

/** @returns {jQuery} The visible text input of the combobox (#location_id_text_input) */
Grocy.Components.LocationPicker.GetInputElement = function ()
{
	return $('#location_id_text_input');
}

/** @returns {string} The currently selected location id */
Grocy.Components.LocationPicker.GetValue = function ()
{
	return $('#location_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Grocy.Components.LocationPicker.SetValue = function (value)
{
	Grocy.Components.LocationPicker.GetInputElement().val(value);
	Grocy.Components.LocationPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given location id directly, refreshing the combobox display */
Grocy.Components.LocationPicker.SetId = function (value)
{
	Grocy.Components.LocationPicker.GetPicker().val(value);
	Grocy.Components.LocationPicker.GetPicker().data('combobox').refresh();
	Grocy.Components.LocationPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Grocy.Components.LocationPicker.Clear = function ()
{
	Grocy.Components.LocationPicker.SetValue('');
	Grocy.Components.LocationPicker.SetId(null);
}

$(".location-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by location name (from the template's data-prefill-by-name attribute on the wrapper),
// then focus the configured next input (data-next-input-selector)
var prefillByName = Grocy.Components.LocationPicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#location_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#location_id').val(possibleOptionElement.val());
		$('#location_id').data('combobox').refresh();
		$('#location_id').trigger('change');

		var nextInputElement = $(Grocy.Components.LocationPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by location id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Grocy.Components.LocationPicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#location_id').val(prefillById);
	$('#location_id').data('combobox').refresh();
	$('#location_id').trigger('change');

	var nextInputElement = $(Grocy.Components.LocationPicker.GetPicker().parent().data('next-input-selector').toString());
	nextInputElement.focus();
}
