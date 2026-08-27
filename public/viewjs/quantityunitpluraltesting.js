// Powers the quantity unit plural form testing view (views/quantityunitpluraltesting.blade.php),
// usually embedded from the quantity unit edit form: live-previews which singular/plural form is used for a given amount.

$("#qu_id").change(function(event)
{
	RefreshQuPluralTestingResult();
});

$("#amount").keyup(function(event)
{
	RefreshQuPluralTestingResult();
});

$("#amount").change(function(event)
{
	RefreshQuPluralTestingResult();
});

/**
 * Renders the pluralization preview for the entered amount, using the
 * data-singular-form / data-plural-form attributes of the selected #qu_id option
 * (provided by the Blade template) and the __n() pluralization helper.
 */
function RefreshQuPluralTestingResult()
{
	var singularForm = $("#qu_id option:selected").data("singular-form");
	var pluralForm = $("#qu_id option:selected").data("plural-form");
	var amount = $("#amount").val();

	if (!singularForm || !amount)
	{
		return;
	}

	animateCSS("h2", "flash");
	$("#result").text(__n(amount, singularForm, pluralForm, true));
}

// Preselect the quantity unit passed via the "qu" URI parameter (set when opened from the quantity unit edit form)
if (GetUriParam("qu") !== undefined)
{
	$("#qu_id").val(GetUriParam("qu"));
	$("#qu_id").trigger("change");
}

setTimeout(function()
{
	$("#amount").focus();
}, Grocy.FormFocusDelay);
