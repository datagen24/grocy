// Powers the recipe ingredient (recipe position) create/edit form (views/recipeposform.blade.php),
// embedded in a modal from the recipe form: saves the ingredient via the objects/recipes_pos API.

// Guards the product picker change handler: on the very first (programmatic) trigger in edit mode the
// saved quantity unit must be kept instead of resetting to the product's default
Grocy.RecipePosFormInitialLoadDone = false;

// Form submit: POSTs objects/recipes_pos (create) or PUTs objects/recipes_pos/{id} (edit, id from Grocy.EditObjectId;
// the owning recipe comes from Grocy.EditObjectParentId). In the InplaceAddBarcodeToExistingProduct flow the scanned
// barcode (from the URI) is additionally saved to objects/product_barcodes. On success the parent recipe form is
// notified (IngredientsChanged) and the modal is closed
$('#save-recipe-pos-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("recipe-pos-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#recipe-pos-form').serializeJSON();
	jsonData.recipe_id = Grocy.EditObjectParentId;
	delete jsonData.display_amount;

	Grocy.FrontendHelpers.BeginUiBusy("recipe-pos-form");

	if (GetUriParam("flow") === "InplaceAddBarcodeToExistingProduct")
	{
		var jsonDataBarcode = {};
		jsonDataBarcode.barcode = GetUriParam("barcode");
		jsonDataBarcode.product_id = jsonData.product_id;

		Grocy.Api.Post('objects/product_barcodes', jsonDataBarcode,
			function(result)
			{
				$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
				$('#barcode-lookup-disabled-hint').addClass('d-none');
				$('#barcode-lookup-hint').removeClass('d-none');
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("inventory-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/recipes_pos', jsonData,
			function(result)
			{
				window.parent.postMessage(WindowMessageBag("IngredientsChanged"), Grocy.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("recipe-pos-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Api.Put('objects/recipes_pos/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				window.parent.postMessage(WindowMessageBag("IngredientsChanged"), Grocy.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("recipe-pos-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Product selection: loads stock/products/{id} to reload the amount/quantity unit picker for that product,
// presets the "don't check stock fulfillment" checkbox from the product's setting (create mode) and
// defaults the quantity unit to the product's stock unit (unless "only check single unit in stock" is on)
Grocy.Components.ProductPicker.GetPicker().on('change', function(e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Grocy.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				if (!Grocy.RecipePosFormInitialLoadDone)
				{
					Grocy.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id, true);
				}
				else
				{
					Grocy.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				}

				if (Grocy.Mode == "create")
				{
					$("#not_check_stock_fulfillment").prop("checked", productDetails.product.not_check_stock_fulfillment_for_recipes == 1);
				}

				if (!$("#only_check_single_unit_in_stock").prop("checked") && Grocy.RecipePosFormInitialLoadDone)
				{
					Grocy.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);
				}

				$('#display_amount').focus();
				Grocy.FrontendHelpers.ValidateForm('recipe-pos-form');
				Grocy.RecipePosFormInitialLoadDone = true;
			},
			function(xhr)
			{
				console.error(xhr);
			}
		);
	}
});

Grocy.FrontendHelpers.ValidateForm('recipe-pos-form');

// Initial focus/prefill: trigger the product change handler when a product is preselected
// (product URI parameter or edit mode / an active picker workflow), otherwise focus the product picker
if (!Grocy.Components.ProductPicker.InAnyFlow())
{
	if (GetUriParam("product") !== undefined || Grocy.EditMode == "edit")
	{
		Grocy.Components.ProductPicker.GetPicker().trigger('change');

		setTimeout(function()
		{
			$("#display_amount").focus();
		}, Grocy.FormFocusDelay);
	}
	else
	{
		setTimeout(function()
		{
			Grocy.Components.ProductPicker.GetInputElement().focus();
		}, Grocy.FormFocusDelay);
	}
}
else
{
	Grocy.Components.ProductPicker.GetPicker().trigger('change');

	if (Grocy.Components.ProductPicker.InProductModifyWorkflow())
	{
		setTimeout(function()
		{
			Grocy.Components.ProductPicker.GetInputElement().focus();
		}, Grocy.FormFocusDelay);
	}
}

if (Grocy.EditMode == "create")
{
	Grocy.RecipePosFormInitialLoadDone = true;
}

// Amount field focus: bounce back to the product picker while no product is selected, otherwise select-all
$('#display_amount').on('focus', function(e)
{
	if (Grocy.Components.ProductPicker.GetValue().length === 0)
	{
		setTimeout(function()
		{
			Grocy.Components.ProductPicker.GetInputElement().focus();
		}, Grocy.FormFocusDelay);
	}
	else
	{
		$(this).select();
	}
});

// Live validation while typing / on unit change
$('#recipe-pos-form input').keyup(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('recipe-pos-form');
});

$('#qu_id').change(function(event)
{
	Grocy.FrontendHelpers.ValidateForm('recipe-pos-form');
});

// Enter submits the form (when valid)
$('#recipe-pos-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('recipe-pos-form'))
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
		$("#display_amount").attr("min", Grocy.DefaultMinAmount);
		Grocy.Components.ProductAmountPicker.AllowAnyQu(true);
		Grocy.FrontendHelpers.ValidateForm("recipe-pos-form");
	}
	else
	{
		$("#display_amount").attr("min", "0");
		Grocy.Components.ProductPicker.GetPicker().trigger("change"); // Selects the default quantity unit of the selected product
		Grocy.Components.ProductAmountPicker.AllowAnyQuEnabled = false;
		Grocy.FrontendHelpers.ValidateForm("recipe-pos-form");
	}
});

if ($("#only_check_single_unit_in_stock").prop("checked"))
{
	Grocy.Components.ProductAmountPicker.AllowAnyQu(true);
}
