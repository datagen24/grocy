// Powers the shopping list settings section (shoppinglistsettings.blade.php): initializes
// checkboxes/inputs from Grocy.UserSettings (actual persistence is handled generically by
// the .user-setting-control click handler elsewhere, not in this file).

// Reflect the stored user settings onto their respective checkboxes on page load
if (BoolVal(Grocy.UserSettings.shopping_list_to_stock_workflow_auto_submit_when_prefilled))
{
	$("#shopping_list_to_stock_workflow_auto_submit_when_prefilled").prop("checked", true);
}

if (BoolVal(Grocy.UserSettings.shopping_list_show_calendar))
{
	$("#shopping_list_show_calendar").prop("checked", true);
}

if (BoolVal(Grocy.UserSettings.shopping_list_round_up))
{
	$("#shopping_list_round_up").prop("checked", true);
}

if (BoolVal(Grocy.UserSettings.shopping_list_auto_add_below_min_stock_amount))
{
	$("#shopping_list_auto_add_below_min_stock_amount").prop("checked", true);
}

$("#shopping_list_auto_add_below_min_stock_amount_list_id").val(Grocy.UserSettings.shopping_list_auto_add_below_min_stock_amount_list_id);

// Enable/disable the target-list dropdown depending on whether the auto-add feature is on
$("#shopping_list_auto_add_below_min_stock_amount").on("click", function()
{
	if (this.checked)
	{
		$("#shopping_list_auto_add_below_min_stock_amount_list_id").removeAttr("disabled");
	}
	else
	{
		$("#shopping_list_auto_add_below_min_stock_amount_list_id").attr("disabled", "");
	}
});

RefreshLocaleNumberInput();
