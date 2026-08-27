// Powers the tasks settings section (taskssettings.blade.php): initializes the
// "due soon" threshold input from Grocy.UserSettings (persistence is handled generically
// elsewhere by the .user-setting-control click handler, not in this file).
$("#tasks_due_soon_days").val(Grocy.UserSettings.tasks_due_soon_days);

RefreshLocaleNumberInput();
