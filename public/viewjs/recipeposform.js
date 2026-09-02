// Powers the recipe ingredient (recipe position) create/edit form (views/recipeposform.blade.php),
// embedded in a modal from the recipe form: saves the ingredient via the objects/recipes_pos API.

// Guards the product picker change handler: on the very first (programmatic) trigger in edit mode the
// saved quantity unit must be kept instead of resetting to the product's default
Victual.RecipePosFormInitialLoadDone = false;

// Form submit: POSTs objects/recipes_pos (create) or PUTs objects/recipes_pos/{id} (edit, id from Victual.EditObjectId;
// the owning recipe comes from Victual.EditObjectParentId). In the InplaceAddBarcodeToExistingProduct flow the scanned
// barcode (from the URI) is additionally saved to objects/product_barcodes. On success the parent recipe form is
// notified (IngredientsChanged) and the modal is closed
$('#save-recipe-pos-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("recipe-pos-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#recipe-pos-form').serializeJSON();
	jsonData.recipe_id = Victual.EditObjectParentId;
	delete jsonData.display_amount;

	Victual.FrontendHelpers.BeginUiBusy("recipe-pos-form");

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
				Victual.FrontendHelpers.EndUiBusy("inventory-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/recipes_pos', jsonData,
			function(result)
			{
				window.parent.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("recipe-pos-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/recipes_pos/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				window.parent.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("recipe-pos-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Product selection: loads stock/products/{id} to reload the amount/quantity unit picker for that product,
// presets the "don't check stock fulfillment" checkbox from the product's setting (create mode) and
// defaults the quantity unit to the product's stock unit (unless "only check single unit in stock" is on)
Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				if (!Victual.RecipePosFormInitialLoadDone)
				{
					Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id, true);
				}
				else
				{
					Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				}

				if (Victual.Mode == "create")
				{
					$("#not_check_stock_fulfillment").prop("checked", productDetails.product.not_check_stock_fulfillment_for_recipes == 1);
				}

				if (!$("#only_check_single_unit_in_stock").prop("checked") && Victual.RecipePosFormInitialLoadDone)
				{
					Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);
				}

				$('#display_amount').focus();
				Victual.FrontendHelpers.ValidateForm('recipe-pos-form');
				Victual.RecipePosFormInitialLoadDone = true;
			}
		);
	}
});

Victual.FrontendHelpers.ValidateForm('recipe-pos-form');

// Initial focus/prefill: trigger the product change handler when a product is preselected
// (product URI parameter or edit mode / an active picker workflow), otherwise focus the product picker
if (!Victual.Components.ProductPicker.InAnyFlow())
{
	if (GetUriParam("product") !== undefined || Victual.EditMode == "edit")
	{
		Victual.Components.ProductPicker.GetPicker().trigger('change');

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

if (Victual.EditMode == "create")
{
	Victual.RecipePosFormInitialLoadDone = true;
}

// Amount field focus: bounce back to the product picker while no product is selected, otherwise select-all
$('#display_amount').on('focus', function(e)
{
	if (Victual.Components.ProductPicker.GetValue().length === 0)
	{
		setTimeout(function()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);
	}
	else
	{
		$(this).select();
	}
});

// Live validation while typing / on unit change
$('#recipe-pos-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('recipe-pos-form');
});

$('#qu_id').change(function(event)
{
	Victual.FrontendHelpers.ValidateForm('recipe-pos-form');
});

// Enter submits the form (when valid)
$('#recipe-pos-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('recipe-pos-form'))
		{
			return false;
		}
		else
		{
			$('#save-recipe-pos-button').click();
		}
	}
});

// "Only check if any amount is in stock": allows any quantity unit (no conversion to the stock unit needed)
// and relaxes the minimum amount; unchecking restores the product's default unit
$("#only_check_single_unit_in_stock").on("change", function()
{
	if (this.checked)
	{
		$("#display_amount").attr("min", Victual.DefaultMinAmount);
		Victual.Components.ProductAmountPicker.AllowAnyQu(true);
		Victual.FrontendHelpers.ValidateForm("recipe-pos-form");
	}
	else
	{
		$("#display_amount").attr("min", "0");
		Victual.Components.ProductPicker.GetPicker().trigger("change"); // Selects the default quantity unit of the selected product
		Victual.Components.ProductAmountPicker.AllowAnyQuEnabled = false;
		Victual.FrontendHelpers.ValidateForm("recipe-pos-form");
	}
});

if ($("#only_check_single_unit_in_stock").prop("checked"))
{
	Victual.Components.ProductAmountPicker.AllowAnyQu(true);
}
