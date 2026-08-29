// Night mode (dark theme) handling: applies/removes the dark stylesheet based on
// the user settings "night_mode" ("on" | "off" | "follow-system"), the optional
// automatic time range ("auto_night_mode_enabled" + "HH:mm" from/to range) and the
// system color scheme; also wires up the related controls on the user settings page.
// Loaded on every page; re-evaluated periodically so the auto time range takes
// effect without a reload.

// Manual night mode radio buttons (on/off/follow-system) on the settings page
$("input.user-setting-control:radio[name=night-mode]").on("change", function()
{
	Victual.UserSettings.night_mode = $("input.user-setting-control:radio[name=night-mode]:checked").val();
	Victual.FrontendHelpers.SaveUserSetting("night_mode", Victual.UserSettings.night_mode, true);
	CheckNightMode();
});

// Auto night mode checkbox: the time range inputs are only editable while enabled
$("#auto-night-mode-enabled").on("change", function()
{
	var value = $(this).is(":checked");
	$("#auto-night-mode-time-range-from").prop("readonly", !value);
	$("#auto-night-mode-time-range-to").prop("readonly", !value);

	if (!value && !BoolVal(Victual.UserSettings.night_mode_enabled_internal))
	{
		$("body").removeClass("night-mode");
	}

	// Force disable night mode when auto night mode is enabled
	if (value)
	{
		$("#night-mode-enabled").prop("checked", false);
		$("#night-mode-enabled").trigger("change");
	}
});

// Validate the time range inputs (must be a strict "HH:mm" time) while typing
$(document).on("keyup", "#auto-night-mode-time-range-from, #auto-night-mode-time-range-to", function()
{
	var value = $(this).val();
	var valueIsValid = moment(value, "HH:mm", true).isValid();

	if (valueIsValid)
	{
		$(this).removeClass("bg-danger");
	}
	else
	{
		$(this).addClass("bg-danger");
	}

	CheckNightMode();
});

$("#auto-night-mode-time-range-goes-over-midgnight").on("change", function()
{
	CheckNightMode();
});

/**
 * Determines whether night mode should currently be active (manual setting,
 * auto time range or system color scheme) and applies/removes the night mode
 * stylesheet (/css/victual_night_mode.css) and body class accordingly.
 * The computed on/off state is persisted as the user setting
 * "night_mode_enabled_internal" (so the server can render the correct theme immediately).
 */
function CheckNightMode()
{
	if (Victual.UserId === -1) // Not logged in => always use system preferred color scheme
	{
		Victual.UserSettings.night_mode = "follow-system";
	}

	var nightModeEnabledInternalBefore = Victual.UserSettings.night_mode_enabled_internal;

	if (Victual.UserSettings.night_mode != "follow-system" && BoolVal(Victual.UserSettings.auto_night_mode_enabled))
	{
		var start = moment(Victual.UserSettings.auto_night_mode_time_range_from, "HH:mm", true);
		var end = moment(Victual.UserSettings.auto_night_mode_time_range_to, "HH:mm", true);
		var now = moment();

		if (!start.isValid() || !end.isValid)
		{
			return;
		}

		if (BoolVal(Victual.UserSettings.auto_night_mode_time_range_goes_over_midnight))
		{
			end.add(1, "day");
		}

		if (now.isBetween(start, end)) // We're INSIDE of night mode time range
		{
			Victual.UserSettings.night_mode_enabled_internal = true;
		}
		else // We're OUTSIDE of night mode time range
		{
			Victual.UserSettings.night_mode_enabled_internal = false;
		}
	}
	else
	{
		if (Victual.UserSettings.night_mode == "on")
		{
			Victual.UserSettings.night_mode_enabled_internal = true;
		}
		else if (Victual.UserSettings.night_mode == "off")
		{
			Victual.UserSettings.night_mode_enabled_internal = false;
		}
		else if (Victual.UserSettings.night_mode == "follow-system")
		{
			Victual.UserSettings.night_mode_enabled_internal = window.matchMedia("(prefers-color-scheme: dark)").matches;
		}
	}

	// Only persist the internal on/off state when it actually changed
	if (BoolVal(nightModeEnabledInternalBefore) != BoolVal(Victual.UserSettings.night_mode_enabled_internal))
	{
		Victual.FrontendHelpers.SaveUserSetting("night_mode_enabled_internal", BoolVal(Victual.UserSettings.night_mode_enabled_internal), true);
	}

	if (BoolVal(Victual.UserSettings.night_mode_enabled_internal))
	{
		// Lazily add the night mode stylesheet on first activation
		if (!$("#night-mode-stylesheet").length)
		{
			$("<link>")
				.appendTo("head")
				.attr({
					rel: "stylesheet",
					href: U("/css/victual_night_mode.css")
				});
		}

		$("body").addClass("night-mode");
	}
	else
	{
		$("body").removeClass("night-mode");
	}
}

// Initialize the settings page controls from the current user settings
if (Victual.UserId !== -1)
{
	$("input.user-setting-control:radio[name=night-mode][value=" + Victual.UserSettings.night_mode + "]").prop("checked", true);
	$("#auto-night-mode-enabled").prop("checked", BoolVal(Victual.UserSettings.auto_night_mode_enabled));
	$("#auto-night-mode-time-range-goes-over-midgnight").prop("checked", BoolVal(Victual.UserSettings.auto_night_mode_time_range_goes_over_midnight));
	$("#auto-night-mode-enabled").trigger("change");
	$("#auto-night-mode-time-range-from").val(Victual.UserSettings.auto_night_mode_time_range_from);
	$("#auto-night-mode-time-range-from").trigger("keyup");
	$("#auto-night-mode-time-range-to").val(Victual.UserSettings.auto_night_mode_time_range_to);
	$("#auto-night-mode-time-range-to").trigger("keyup");
}

// Re-check periodically so the auto time range / system scheme is picked up while the page stays open
if (Victual.Mode === "production")
{
	setInterval(CheckNightMode, 60000);
}
else
{
	setInterval(CheckNightMode, 4000);
}

CheckNightMode();
