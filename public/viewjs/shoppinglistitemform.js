// Powers the shopping list item add/edit form (shoppinglistitemform.blade.php). Runs
// inside a modal iframe (or, with the "embedded" URI param, embedded directly e.g. in the
// barcode-scan flow) and can operate in several modes depending on URI params:
// - plain create/edit of a "shopping_list" object (free-text or product-linked item)
// - "updateexistingproduct" -> POST stock/shoppinglist/add-product (merges into an existing entry)
// - "flow=InplaceAddBarcodeToExistingProduct" -> also links a scanned barcode to the picked product
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT.
Victual.ShoppingListItemFormInitialLoadDone = false;

/**
 * Builds the "Added X of Y to the shopping list Z" message posted to the parent window,
 * which renders it through toastr - an HTML sink. The product name, both quantity unit
 * names and the shopping list name are all text columns that can contain markup, so each
 * is escaped here, at the point of use (sweep finding S29). Two of them are read back out
 * of the DOM with .text()/.attr(), which return the *decoded* string, so escaping applied
 * when they were written into the option is not in effect by the time they arrive here.
 * @param {number} displayAmount Amount in the picked quantity unit
 * @param {Object} productDetails GET /stock/products/{id} response
 * @returns {string} Ready to render message
 */
function ShoppingListItemAddedMessage(displayAmount, productDetails)
{
	var escape = Victual.FrontendHelpers.EscapeHtml;
	var amountWithQu = displayAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts })
		+ " " + __n(displayAmount, escape($("#qu_id option:selected").text()), escape($("#qu_id option:selected").attr("data-qu-name-plural")), true);

	return __t("Added %1$s of %2$s to the shopping list \"%3$s\"", amountWithQu, escape(productDetails.product.name), escape($("#shopping_list_id option:selected").text()));
}

// Main submit handler: validates, normalizes amount fields, then dispatches to the
// correct API call/mode; on success either postMessages the parent (embedded mode)
// to refresh the shopping list and close the modal, or navigates back to the list
$('#save-shoppinglist-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("shoppinglist-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#shoppinglist-form').serializeJSON();
	var displayAmount = Number.parseFloat(jsonData.display_amount);
	if (!jsonData.product_id)
	{
		jsonData.amount = jsonData.display_amount;
	}
	delete jsonData.display_amount;

	Victual.FrontendHelpers.BeginUiBusy("shoppinglist-form");

	// Barcode-scan flow: additionally link the scanned barcode to the selected product
	if (GetUriParam("flow") === "InplaceAddBarcodeToExistingProduct")
	{
		var jsonDataBarcode = {};
		jsonDataBarcode.barcode = GetUriParam("barcode");
		jsonDataBarcode.product_id = jsonData.product_id;

		Victual.Api.Post('objects/product_barcodes', jsonDataBarcode,
			function(result)
			{
				$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
				$('#barcode-lookup-disabled-hint').addClass('d-none');
				$('#barcode-lookup-hint').removeClass('d-none');
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglist-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

	// Merge mode: add/increase amount for a product already on the list rather than
	// creating a duplicate row (server endpoint resolves the existing item itself)
	if (GetUriParam("updateexistingproduct") !== undefined)
	{
		jsonData.product_amount = jsonData.amount;
		delete jsonData.amount;

		jsonData.list_id = jsonData.shopping_list_id;
		delete jsonData.shopping_list_id;

		Victual.Api.Post('stock/shoppinglist/add-product', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save();

				if (GetUriParam("embedded") !== undefined)
				{
					Victual.Api.Get('stock/products/' + jsonData.product_id,
						function(productDetails)
						{
							if (GetUriParam("product") !== undefined)
							{
								window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", ShoppingListItemAddedMessage(displayAmount, productDetails)), Victual.BaseUrl);
							}

							window.parent.postMessage(WindowMessageBag("ShoppingListChanged", $("#shopping_list_id").val().toString()), Victual.BaseUrl);
							window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
						}
					);
				}
				else
				{
					window.location.href = U('/shoppinglist?list=' + encodeURIComponent($("#shopping_list_id").val().toString()));
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglist-form");
				Victual.Api.DefaultErrorHandler(xhr);
			}
		);
	}
	// Plain create of a new shopping_list row
	else if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/shopping_list', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save();

				if (GetUriParam("embedded") !== undefined)
				{
					if (jsonData.product_id)
					{
						Victual.Api.Get('stock/products/' + jsonData.product_id,
							function(productDetails)
							{
								if (GetUriParam("product") !== undefined)
								{
									window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", ShoppingListItemAddedMessage(displayAmount, productDetails)), Victual.BaseUrl);
								}

								window.parent.postMessage(WindowMessageBag("ShoppingListChanged", $("#shopping_list_id").val().toString()), Victual.BaseUrl);
								window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
							}
						);
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ShoppingListChanged", $("#shopping_list_id").val().toString()), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
				}
				else
				{
					window.location.href = U('/shoppinglist?list=' + encodeURIComponent($("#shopping_list_id").val().toString()));
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglist-form");
				Victual.Api.DefaultErrorHandler(xhr);
			}
		);
	}
	// Plain edit of an existing shopping_list row
	else
	{
		Victual.Api.Put('objects/shopping_list/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save();

				if (GetUriParam("embedded") !== undefined)
				{
					if (jsonData.product_id)
					{
						Victual.Api.Get('stock/products/' + jsonData.product_id,
							function(productDetails)
							{
								if (GetUriParam("product") !== undefined)
								{
									window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", ShoppingListItemAddedMessage(displayAmount, productDetails)), Victual.BaseUrl);
								}

								window.parent.postMessage(WindowMessageBag("ShoppingListChanged", $("#shopping_list_id").val().toString()), Victual.BaseUrl);
								window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
							}
						);
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ShoppingListChanged", $("#shopping_list_id").val().toString()), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
				}
				else
				{
					window.location.href = U('/shoppinglist?list=' + encodeURIComponent($("#shopping_list_id").val().toString()));
				}
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("shoppinglist-form");
				Victual.Api.DefaultErrorHandler(xhr);
			}
		);
	}
});

// When a product is picked, reloads the amount picker for that product's stock quantity
// unit (and defaults it to the purchase QU on subsequent changes, but not the initial
// load which should keep the stock QU), defaults the amount to 1 if empty, and
// re-validates the form
Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				if (!Victual.ShoppingListItemFormInitialLoadDone)
				{
					Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id, true);
				}
				else
				{
					Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
					Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.default_quantity_unit_purchase.id);
				}

				if (!$("#display_amount").val())
				{
					$("#display_amount").val(1);
					$("#display_amount").trigger("change");
				}

				setTimeout(function()
				{
					$('#display_amount').focus();
				}, Victual.FormFocusDelay);
				Victual.FrontendHelpers.ValidateForm('shoppinglist-form');
				Victual.ShoppingListItemFormInitialLoadDone = true;
			}
		);
	}

	$("#note").trigger("input");
	$("#product_id").trigger("input");
});

// Initial form state: in edit mode, load the already-selected product's details
Victual.FrontendHelpers.ValidateForm('shoppinglist-form');

if (Victual.EditMode === "edit")
{
	Victual.Components.ProductPicker.GetPicker().trigger('change');
}

if (Victual.EditMode == "create")
{
	Victual.ShoppingListItemFormInitialLoadDone = true;
}

// Selects the amount field's content on focus for quick overtyping
$('#display_amount').on('focus', function(e)
{
	$(this).select();
});

// Live-validates on every keystroke
$('#shoppinglist-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('shoppinglist-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#shoppinglist-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('shoppinglist-form'))
		{
			return false;
		}
		else
		{
			$('#save-shoppinglist-button').click();
		}
	}
});

// Pre-fill fields from URI params (e.g. links from the shopping list "add missing" flows)
if (GetUriParam("list") !== undefined)
{
	$("#shopping_list_id").val(GetUriParam("list"));
}

if (GetUriParam("amount") !== undefined)
{
	$("#display_amount").val(Number.parseFloat(GetUriParam("amount")));
	RefreshLocaleNumberInput();
	$(".input-group-productamountpicker").trigger("change");
	Victual.FrontendHelpers.ValidateForm('shoppinglist-form');
}

// Focus management: put the cursor in the most useful field depending on whether a
// product picker "flow" (e.g. barcode scan / product modify) is in progress
if (!Victual.Components.ProductPicker.InAnyFlow())
{
	if (GetUriParam("product") !== undefined || Victual.EditMode == "edit")
	{
		if (GetUriParam("updateexistingproduct") != null)
		{
			Victual.Components.ProductPicker.GetPicker().trigger('change');
		}

		setTimeout(function()
		{
			$("#display_amount").focus();
		}, Victual.FormFocusDelay);
	}
	else
	{
		setTimeout(function()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);
	}
}
else
{
	Victual.Components.ProductPicker.GetPicker().trigger('change');

	if (Victual.Components.ProductPicker.InProductModifyWorkflow())
	{
		setTimeout(function()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);
	}
}

// An item needs either a linked product or a free-text note; keep exactly one of these
// three fields marked required based on what's currently filled in
var eitherRequiredFields = $("#product_id,#product_id_text_input,#note");
eitherRequiredFields.prop('required', "");
eitherRequiredFields.on('input', function()
{
	eitherRequiredFields.not(this).prop('required', !$(this).val().length);
	Victual.FrontendHelpers.ValidateForm('shoppinglist-form');
});
eitherRequiredFields.trigger("input");

if (GetUriParam("product-name") != null)
{
	Victual.Components.ProductPicker.GetPicker().trigger('change');
}

// Load values for any configured custom userfields
Victual.Components.UserfieldsForm.Load();
