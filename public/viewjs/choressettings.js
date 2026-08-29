// View script for the chores settings page (views/choressettings.blade.php):
// prefills the form from Victual.UserSettings (saving is handled generically elsewhere)

$("#chores_due_soon_days").val(Victual.UserSettings.chores_due_soon_days);

if (BoolVal(Victual.UserSettings.chores_overview_swap_tracking_buttons))
{
	$("#chores_overview_swap_tracking_buttons").prop("checked", true);
}

RefreshLocaleNumberInput();
