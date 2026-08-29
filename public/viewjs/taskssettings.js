// Powers the tasks settings section (taskssettings.blade.php): initializes the
// "due soon" threshold input from Victual.UserSettings (persistence is handled generically
// elsewhere by the .user-setting-control click handler, not in this file).
$("#tasks_due_soon_days").val(Victual.UserSettings.tasks_due_soon_days);

RefreshLocaleNumberInput();
