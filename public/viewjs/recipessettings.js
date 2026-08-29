// Powers the recipes settings view (views/recipessettings.blade.php):
// initializes the settings checkboxes from the corresponding Victual.UserSettings values (saving is handled generically via the user-setting data attributes in the template).

if (BoolVal(Victual.UserSettings.recipe_ingredients_group_by_product_group))
{
	$("#recipe_ingredients_group_by_product_group").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.recipes_show_list_side_by_side))
{
	$("#recipes_show_list_side_by_side").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.recipes_show_ingredient_checkbox))
{
	$("#recipes_show_ingredient_checkbox").prop("checked", true);
}
