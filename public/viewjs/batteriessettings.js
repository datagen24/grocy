// View script for the batteries settings page (views/batteriessettings.blade.php):
// prefills the form from Grocy.UserSettings (saving is handled generically elsewhere)

$("#batteries_due_soon_days").val(Grocy.UserSettings.batteries_due_soon_days);

RefreshLocaleNumberInput();
