// Powers the quantity unit conversion create/edit form (views/quantityunitconversionform.blade.php),
// used both for product-specific conversions (embedded from the product form) and default conversions of a unit
// (opened with a "qu-unit" URI parameter from the quantity unit form): saves via the objects/quantity_unit_conversions API.

// Form submit: POSTs objects/quantity_unit_conversions (create) or PUTs objects/quantity_unit_conversions/{id} (edit, id from Victual.EditObjectId).
// Afterwards: with a "qu-unit" URI parameter it returns to that quantity unit's edit page (or reloads the parent when embedded),
// otherwise it notifies the parent product form (ProductQUConversionChanged) and closes the modal
$('#save-quconversion-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("quconversion-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#quconversion-form').serializeJSON();
	jsonData.from_qu_id = $("#from_qu_id").val();
	Victual.FrontendHelpers.BeginUiBusy("quconversion-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/quantity_unit_conversions', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (typeof GetUriParam("qu-unit") !== "undefined")
					{
						if (GetUriParam("embedded") !== undefined)
						{
							window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
						}
						else
						{
							window.location.href = U("/quantityunit/" + GetUriParam("qu-unit"));
						}
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ProductQUConversionChanged"), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("quconversion-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/quantity_unit_conversions/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (typeof GetUriParam("qu-unit") !== "undefined")
					{
						if (GetUriParam("embedded") !== undefined)
						{
							window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
						}
						else
						{
							window.location.href = U("/quantityunit/" + GetUriParam("qu-unit"));
						}
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ProductQUConversionChanged"), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("quconversion-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live validation + refresh of the conversion preview while typing
$('#quconversion-form input').keyup(function(event)
{
	$('.input-group-qu').trigger('change');
	Victual.FrontendHelpers.ValidateForm('quconversion-form');
});

// Enter submits the form (when valid)
$('#quconversion-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('quconversion-form'))
		{
			return false;
		}
		else
		{
			$('#save-quconversion-button').click();
		}
	}
});

// Unit conversion preview: rejects from == to, then renders both directions of the factor
// ("1 <from> = <factor> <to>" and the inverse "1 <to> = 1/<factor> <from>"), pluralized via
// __n() using the data-plural-form attribute of the selected unit options (provided by the template)
$('.input-group-qu').on('change', function(e)
{
	var fromQuId = $("#from_qu_id").val();
	var toQuId = $("#to_qu_id").val();
	var factor = Number.parseFloat($('#factor').val());

	if (fromQuId == toQuId)
	{
		var validationMessage = __t('This cannot be equal to %s', $("#from_qu_id option:selected").text());
		$("#to_qu_id").parent().find(".invalid-feedback").text(validationMessage);
		$("#to_qu_id")[0].setCustomValidity(validationMessage);
	}
	else
	{
		$("#to_qu_id")[0].setCustomValidity("");
	}

	if (fromQuId && toQuId)
	{
		$('#qu-conversion-info').text(__t('This means 1 %1$s is the same as %2$s %3$s', $("#from_qu_id option:selected").text(), (1.0 * factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }), __n((1.0 * factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }), $("#to_qu_id option:selected").text(), $("#to_qu_id option:selected").data("plural-form"), true)));
		$('#qu-conversion-info').removeClass('d-none');
		$('#qu-conversion-inverse-info').removeClass('d-none');
		$('#qu-conversion-inverse-info').text(__t('This means 1 %1$s is the same as %2$s %3$s', $("#to_qu_id option:selected").text(), (1.0 / factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }), __n((1.0 / factor), $("#from_qu_id option:selected").text(), $("#from_qu_id option:selected").data("plural-form"), true)));
	}
	else
	{
		$('#qu-conversion-info').addClass('d-none');
		$('#qu-conversion-inverse-info').addClass('d-none');
	}

	Victual.FrontendHelpers.ValidateForm('quconversion-form');
});

// Initial setup: load userfields, render the preview once, validate and focus the "from" unit
Victual.Components.UserfieldsForm.Load();
$('.input-group-qu').trigger('change');
Victual.FrontendHelpers.ValidateForm('quconversion-form');
setTimeout(function()
{
	$('#from_qu_id').focus();
}, Victual.FormFocusDelay);

// When editing a default conversion of a specific unit, the "from" unit is fixed
if (GetUriParam("qu-unit") !== undefined)
{
	$("#from_qu_id").attr("disabled", "");
}
