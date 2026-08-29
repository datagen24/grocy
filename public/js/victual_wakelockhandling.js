// Screen wake lock handling: keeps the device screen on via NoSleep.js,
// either always (user setting "keep_screen_on") or only while a fullscreen-card
// is displayed ("keep_screen_on_when_fullscreen_card"). Loaded on every page.

Victual.WakeLock = {};
Victual.WakeLock.NoSleepJsIntance = null; // Lazily created NoSleep.js instance
Victual.WakeLock.InitDone = false; // Whether the wake lock was already enabled once from a user gesture

// Toggle wake lock live when the settings page checkbox changes
$("#keep_screen_on").on("change", function()
{
	var value = $(this).is(":checked");
	if (value)
	{
		Victual.WakeLock.Enable();
	}
	else
	{
		Victual.WakeLock.Disable();
	}
});

/** Enables the screen wake lock (creates the NoSleep.js instance on first use). */
Victual.WakeLock.Enable = function()
{
	if (Victual.WakeLock.NoSleepJsIntance === null)
	{
		Victual.WakeLock.NoSleepJsIntance = new NoSleep();
	}
	Victual.WakeLock.NoSleepJsIntance.enable();
	Victual.WakeLock.InitDone = true;
}

/** Disables the screen wake lock (no-op when it was never enabled). */
Victual.WakeLock.Disable = function()
{
	if (Victual.WakeLock.NoSleepJsIntance !== null)
	{
		Victual.WakeLock.NoSleepJsIntance.disable();
	}
}

// Handle "Keep screen on while displaying a fullscreen-card" when the body class "fullscreen-card" has changed
new MutationObserver(function(mutations)
{
	if (BoolVal(Victual.UserSettings.keep_screen_on_when_fullscreen_card) && !BoolVal(Victual.UserSettings.keep_screen_on))
	{
		mutations.forEach(function(mutation)
		{
			if (mutation.attributeName === "class")
			{
				var attributeValue = $(mutation.target).prop(mutation.attributeName);
				if (attributeValue.contains("fullscreen-card"))
				{
					Victual.WakeLock.Enable();
				}
				else
				{
					Victual.WakeLock.Disable();
				}
			}
		});
	}
}).observe(document.body, {
	attributes: true
});

// Enabling NoSleep.Js only works in a user input event handler,
// so if the user wants to keep the screen on always,
// do this in on the first click on anything
$(document).click(function()
{
	if (Victual.WakeLock.InitDone === false && BoolVal(Victual.UserSettings.keep_screen_on))
	{
		Victual.WakeLock.Enable();
	}

	Victual.WakeLock.InitDone = true;
});
