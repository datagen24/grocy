// Implements the ProductAmountPicker widget (views/components/productamountpicker.blade.php):
// pairs the #display_amount input (entered in whatever QU the user picks) with the #qu_id
// select (populated from Victual.QuantityUnitConversionsResolved for the product) and the hidden
// #amount input holding the amount converted to the destination/stock quantity unit.
// Public API: Reload(productId, destinationQuId), SetQuantityUnit(quId), AllowAnyQu(), Reset().
Victual.Components.ProductAmountPicker = {};
Victual.Components.ProductAmountPicker.AllowAnyQuEnabled = false;

/**
 * (Re)populates the #qu_id dropdown with the quantity units convertible to/from destinationQuId
 * for the given product (based on Victual.QuantityUnitConversionsResolved), and converts the
 * currently displayed amount to the newly selected QU on first load.
 * @param {number} productId Product to load QU conversions for.
 * @param {number} destinationQuId The QU the resulting #amount value should be expressed in
 *   (usually the stock QU).
 * @param {boolean} [forceInitialDisplayQu=false] When true, resets the selection back to the
 *   QU configured as data-initial-qu-id even on a reload.
 */
Victual.Components.ProductAmountPicker.Reload = function (productId, destinationQuId, forceInitialDisplayQu = false)
{
	var conversionsForProduct = FindAllObjectsInArrayByPropertyValue(Victual.QuantityUnitConversionsResolved, 'product_id', productId);

	if (!Victual.Components.ProductAmountPicker.AllowAnyQuEnabled)
	{
		$("#qu_id").find("option").remove().end();
		if (!$("#qu_id").hasAttr("required"))
		{
			$("#qu_id").append('<option></option>');
		}

		$("#qu_id").attr("data-destination-qu-name", FindObjectInArrayByPropertyValue(Victual.QuantityUnits, 'id', destinationQuId).name);
		$("#qu_id").attr("data-destination-qu-name-plural", FindObjectInArrayByPropertyValue(Victual.QuantityUnits, 'id', destinationQuId).name_plural);

		conversionsForProduct.forEach(conversion =>
		{
			if (conversion.to_qu_id == destinationQuId)
			{
				conversion.factor = 1;
			}

			// Only conversions related to the destination QU are needed
			// + only add one conversion per to_qu_id (multiple ones can be a result of contradictory definitions = user input bullshit)
			if ((conversion.from_qu_id == destinationQuId || conversion.to_qu_id == destinationQuId) && !$('#qu_id option[value="' + conversion.to_qu_id + '"]').length)
			{
				$("#qu_id").append('<option value="' + conversion.to_qu_id + '" data-qu-factor="' + conversion.factor + '" data-qu-name-plural="' + conversion.to_qu_name_plural + '">' + conversion.to_qu_name + '</option>');
			}
		});
	}

	if (!Victual.Components.ProductAmountPicker.InitialValueSet || forceInitialDisplayQu)
	{
		$("#qu_id").val($("#qu_id").attr("data-initial-qu-id"));
	}

	if (!Victual.Components.ProductAmountPicker.InitialValueSet)
	{
		var amount = Number.parseFloat($("#display_amount").val());
		var factor = Number.parseFloat($("#qu_id option:selected").attr("data-qu-factor"));
		var convertedAmount = (amount * factor).toLocaleString("en", { minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts });
		$("#display_amount").val(convertedAmount);

		Victual.Components.ProductAmountPicker.InitialValueSet = true;
	}

	if (conversionsForProduct.length === 1 && !forceInitialDisplayQu)
	{
		$("#qu_id").val($("#qu_id option:first").val());
	}

	if ($('#qu_id option').length == 1)
	{
		$("#qu_id").attr("disabled", "");
	}
	else
	{
		$("#qu_id").removeAttr("disabled");
	}

	$(".input-group-productamountpicker").trigger("change");
}

/** Selects the given quantity unit in #qu_id (option must already be present) */
Victual.Components.ProductAmountPicker.SetQuantityUnit = function (quId)
{
	$("#qu_id").val(quId);
}

/**
 * Switches the picker into "any quantity unit" mode: replaces #qu_id's options with the full
 * list of Victual.QuantityUnits (each with a 1:1 factor) instead of only the product's configured
 * conversions - used where a specific product isn't known yet (e.g. generic stock entries).
 * @param {boolean} [keepInitialQu=false] When true, re-selects data-initial-qu-id afterwards.
 */
Victual.Components.ProductAmountPicker.AllowAnyQu = function (keepInitialQu = false)
{
	Victual.Components.ProductAmountPicker.AllowAnyQuEnabled = true;

	$("#qu_id").find("option").remove().end();
	if (!$("#qu_id").hasAttr("required"))
	{
		$("#qu_id").append('<option></option>');
	}

	Victual.QuantityUnits.forEach(qu =>
	{
		$("#qu_id").append('<option value="' + qu.id + '" data-qu-factor="1" data-qu-name-plural="' + qu.name_plural + '">' + qu.name + '</option>');
	});

	if (keepInitialQu)
	{
		Victual.Components.ProductAmountPicker.SetQuantityUnit($("#qu_id").attr("data-initial-qu-id"));
	}

	$("#qu_id").removeAttr("disabled");

	$(".input-group-productamountpicker").trigger("change");
}

/** Clears the QU options and any conversion info hint, e.g. when no product is selected */
Victual.Components.ProductAmountPicker.Reset = function ()
{
	$("#qu_id").find("option").remove();
	$("#qu-conversion-info").addClass("d-none");
	$("#qu-display_amount-info").val("");
}

// Recomputes the hidden #amount (converted to the destination QU) whenever the displayed amount
// or selected QU changes, and shows/hides the "this equals X Y" conversion hint
$(".input-group-productamountpicker").on("change", function ()
{
	var selectedQuName = $("#qu_id option:selected").text();
	var quFactor = $("#qu_id option:selected").attr("data-qu-factor");
	var amount = $("#display_amount").val();
	var destinationAmount = amount / quFactor;
	var destinationQuName = __n(destinationAmount, $("#qu_id").attr("data-destination-qu-name"), $("#qu_id").attr("data-destination-qu-name-plural"), true);

	if ($("#qu_id").attr("data-destination-qu-name") == selectedQuName || Victual.Components.ProductAmountPicker.AllowAnyQuEnabled || !amount || !selectedQuName)
	{
		$("#qu-conversion-info").addClass("d-none");
	}
	else
	{
		$("#qu-conversion-info").removeClass("d-none");
		$("#qu-conversion-info").text(__t("This equals %1$s %2$s", destinationAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }), destinationQuName));
	}

	var n = Victual.UserSettings.stock_decimal_places_amounts;
	if (n <= 0)
	{
		n = 1;
	}

	$("#amount").val(destinationAmount.toFixed(n).replace(/0*$/g, '')).trigger("change");
});

// Keep the derived #amount/conversion info in sync while typing
$("#display_amount").on("keyup", function ()
{
	$(".input-group-productamountpicker").trigger("change");
});
