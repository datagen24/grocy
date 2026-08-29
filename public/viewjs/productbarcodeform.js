// Powers the product barcode create/edit form (views/productbarcodeform.blade.php), embedded in a modal from the product edit form:
// saves a barcode (with optional default amount/quantity unit/store/note) via the objects/product_barcodes API.

// Form submit: POSTs objects/product_barcodes (create) or PUTs objects/product_barcodes/{id} (edit, id from Victual.EditObjectId);
// display_amount (the amount in the selected purchase unit) is sent as "amount", then the parent window is told to refresh and close the modal
$('#save-barcode-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("barcode-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#barcode-form').serializeJSON();
	jsonData.amount = jsonData.display_amount;
	delete jsonData.display_amount;
	jsonData.qu_id = $("#qu_id").val();

	Victual.FrontendHelpers.BeginUiBusy("barcode-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/product_barcodes', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save()

				window.parent.postMessage(WindowMessageBag("ProductBarcodesChanged"), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("barcode-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Components.UserfieldsForm.Save();
		Victual.Api.Put('objects/product_barcodes/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				window.parent.postMessage(WindowMessageBag("ProductBarcodesChanged"), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("barcode-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live validation on the relevant inputs
$('#barcode').on('keyup', function(e)
{
	Victual.FrontendHelpers.ValidateForm('barcode-form');
});

$('#qu_id').on('change', function(e)
{
	Victual.FrontendHelpers.ValidateForm('barcode-form');
});

$('#display_amount').on('keyup', function(e)
{
	Victual.FrontendHelpers.ValidateForm('barcode-form');
});

// Enter submits the form (when valid)
$('#barcode-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('barcode-form'))
		{
			return false;
		}
		else
		{
			$('#save-barcode-button').click();
		}
	}
});

// Initial setup: load the amount/quantity unit picker for the product this barcode belongs to
// (Victual.EditObjectProduct is provided by the template) and prefill amount/unit in edit mode
Victual.Components.ProductAmountPicker.Reload(Victual.EditObjectProduct.id, Victual.EditObjectProduct.qu_id_purchase);
if (Victual.EditMode == "edit")
{
	$("#display_amount").val(Victual.EditObject.amount);
	$(".input-group-productamountpicker").trigger("change");

	if (Victual.EditObject.qu_id)
	{
		Victual.Components.ProductAmountPicker.SetQuantityUnit(Victual.EditObject.qu_id);
	}
}

Victual.FrontendHelpers.ValidateForm('barcode-form');
setTimeout(function()
{
	$('#barcode').focus();
}, Victual.FormFocusDelay);
RefreshLocaleNumberInput();
Victual.Components.UserfieldsForm.Load()

// Barcode scanner hook: when the camera scanner (Victual.BarcodeScanned event) targets this form's field, fill it in
$(document).on("Victual.BarcodeScanned", function(e, barcode, target)
{
	if (target !== "#barcode")
	{
		return;
	}

	$("#barcode").val(barcode);
});
