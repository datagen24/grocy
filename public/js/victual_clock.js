// Header clock: renders the current date/time in the top navbar
// (#clock-small / #clock-big) when the user setting "show_clock_in_header" is enabled;
// loaded on every page as part of the shared layout.

// Re-evaluate when the setting checkbox (on the user settings page) is toggled
$(document).on("change", "#show-clock-in-header", function()
{
	CheckHeaderClockEnabled();
});

/**
 * Renders the current time into the header clock elements
 * (moment format "l LT" for the small clock, "LLLL" for the big one, both locale aware).
 */
function RefreshHeaderClock()
{
	$("#clock-small").text(moment().format("l LT"));
	$("#clock-big").text(moment().format("LLLL"));
}

Victual.HeaderClockInterval = null;
/**
 * Shows/hides the header clock based on the user setting "show_clock_in_header"
 * and starts/stops the 1 second refresh interval accordingly.
 * Does nothing when not logged in (Victual.UserId === -1).
 */
function CheckHeaderClockEnabled()
{
	if (Victual.UserId === -1)
	{
		return;
	}

	// Refresh the clock in the header every second when enabled
	if (BoolVal(Victual.UserSettings.show_clock_in_header))
	{
		RefreshHeaderClock();
		$("#clock-container").removeClass("d-none");

		Victual.HeaderClockInterval = setInterval(function()
		{
			RefreshHeaderClock();
		}, 1000);
	}
	else
	{
		if (Victual.HeaderClockInterval !== null)
		{
			clearInterval(Victual.HeaderClockInterval);
			Victual.HeaderClockInterval = null;
		}

		$("#clock-container").addClass("d-none");
	}
}
CheckHeaderClockEnabled();

// Reflect the current setting in the settings page checkbox (if present on this page)
if (Victual.UserId !== -1 && BoolVal(Victual.UserSettings.show_clock_in_header))
{
	$("#show-clock-in-header").prop("checked", true);
}
