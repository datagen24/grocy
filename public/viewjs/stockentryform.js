// Powers the "edit stock entry" modal form (stockentryform.blade.php). Unlike most forms
// here this is edit-only (PUT stock/entry/{Grocy.EditObjectRowId}) - there's no create
// mode, since new stock entries are made via the purchase/consume workflows.

// Validates and submits the form (PUT, always - no create mode), optionally prints a
// label and saves userfields, then notifies the parent window to broadcast the product
// change, show a success toast (with an inline Undo link) and close the modal
$('#save-stockentry-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("stockentry-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#stockentry-form').serializeJSON();
	Grocy.FrontendHelpers.BeginUiBusy("stockentry-form");

	if (jsonForm.price)
	{
		price = Number.parseFloat(jsonForm.price).toFixed(Grocy.UserSettings.stock_decimal_places_prices_input);
	}

	var jsonData = {};
	jsonData.amount = jsonForm.amount;
	jsonData.best_before_date = Grocy.Components.DateTimePicker.GetValue();
	jsonData.purchased_date = Grocy.Components.DateTimePicker2.GetValue();
	jsonData.note = jsonForm.note;
	jsonData.price = price;
	jsonData.open = $("#open").is(":checked");

	if (Grocy.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
	{
		jsonData.shopping_location_id = Grocy.Components.ShoppingLocationPicker.GetValue();
	}

	if (Grocy.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
	{
		jsonData.location_id = Grocy.Components.LocationPicker.GetValue();
	}
	else
	{
		jsonData.location_id = 1;
	}

	Grocy.Api.Put("stock/entry/" + Grocy.EditObjectRowId, jsonData,
		function(result)
		{
			Grocy.EditObjectId = result[0].transaction_id;

			if (Grocy.FeatureFlags.VICTUAL_FEATURE_FLAG_LABEL_PRINTER && $("#print-label").is(":checked"))
			{
				Grocy.Api.Get('stock/entry/' + Grocy.EditObjectRowId + '/printlabel', function(labelData)
				{
					if (Grocy.Webhooks.labelprinter !== undefined)
					{
						Grocy.FrontendHelpers.RunWebhook(Grocy.Webhooks.labelprinter, labelData);
					}
				});
			}

			Grocy.Components.UserfieldsForm.Save(function()
			{
				var successMessage = __t('Stock entry successfully updated') + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockBookingEntry(\'' + result.id + '\',\'' + Grocy.EditObjectRowId + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

				Grocy.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", Grocy.EditObjectProductId)), Grocy.BaseUrl);
				window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Grocy.BaseUrl);
				window.parent.postMessage(WindowMessageBag("Ready"), Grocy.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
			});
		},
		function(xhr)
		{
			Grocy.FrontendHelpers.EndUiBusy("stockentry-form");
			console.error(xhr);
		}
	);
});

Grocy.FrontendHelpers.ValidateForm('stockentry-form');

// Live-validates on every keystroke
$('#stockentry-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('stockentry-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#stockentry-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('stockentry-form'))
		{
			return false;
		}
		else
		{
			$('#save-stockentry-button').click();
		}
	}
});

// Re-validate as the best-before-date (DateTimePicker) and purchased-date (DateTimePicker2)
// fields change
Grocy.Components.DateTimePicker.GetInputElement().on('change', function(e)
{
	Grocy.FrontendHelpers.ValidateForm('stockentry-form');
});

Grocy.Components.DateTimePicker.GetInputElement().on('keypress', function(e)
{
	Grocy.FrontendHelpers.ValidateForm('stockentry-form');
});

Grocy.Components.DateTimePicker2.GetInputElement().on('change', function(e)
{
	Grocy.FrontendHelpers.ValidateForm('stockentry-form');
});

Grocy.Components.DateTimePicker2.GetInputElement().on('keypress', function(e)
{
	Grocy.FrontendHelpers.ValidateForm('stockentry-form');
});

// Show the product's stock quantity unit name next to the amount field
Grocy.Api.Get('stock/products/' + Grocy.EditObjectProductId,
	function(productDetails)
	{
		$('#amount_qu_unit').text(productDetails.quantity_unit_stock.name);
	},
	function(xhr)
	{
		console.error(xhr);
	}
);

// Selects the amount field's content on focus for quick overtyping
$("#amount").on("focus", function(e)
{
	$(this).select();
});

// Changing the best-before-date pre-checks "print label" (a label is typically wanted
// whenever the due date changes), when label printing is enabled
Grocy.Components.DateTimePicker.GetInputElement().on('change', function(e)
{
	if (Grocy.FeatureFlags.VICTUAL_FEATURE_FLAG_LABEL_PRINTER)
	{
		$("#print-label").prop("checked", true);
	}
});

// Initial setup: load userfields, focus the amount field, run initial validation
Grocy.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#amount').focus();
}, Grocy.FormFocusDelay);
Grocy.FrontendHelpers.ValidateForm("stockentry-form");
