// Powers the general user settings section (usersettings.blade.php): initializes the
// locale dropdown from Grocy.UserSettings (persistence is handled generically elsewhere
// by the .user-setting-control click handler, not in this file).
$("#locale").val(Grocy.UserSettings.locale);

RefreshLocaleNumberInput();
