// Screen wake lock handling: keeps the device screen on via NoSleep.js,
// either always (user setting "keep_screen_on") or only while a fullscreen-card
// is displayed ("keep_screen_on_when_fullscreen_card"). Loaded on every page.

Grocy.WakeLock = {};
Grocy.WakeLock.NoSleepJsIntance = null; // Lazily created NoSleep.js instance
Grocy.WakeLock.InitDone = false; // Whether the wake lock was already enabled once from a user gesture

// Toggle wake lock live when the settings page checkbox changes
$("#keep_screen_on").on("change", function()
{
	var value = $(this).is(":checked");
	if (value)
	{
		Grocy.WakeLock.Enable();
	}
	else
	{
		Grocy.WakeLock.Disable();
	}
});

/** Enables the screen wake lock (creates the NoSleep.js instance on first use). */
Grocy.WakeLock.Enable = function()
{
	if (Grocy.WakeLock.NoSleepJsIntance === null)
	{
		Grocy.WakeLock.NoSleepJsIntance = new NoSleep();
	}
	Grocy.WakeLock.NoSleepJsIntance.enable();
	Grocy.WakeLock.InitDone = true;
}

/** Disables the screen wake lock (no-op when it was never enabled). */
Grocy.WakeLock.Disable = function()
{
	if (Grocy.WakeLock.NoSleepJsIntance !== null)
	{
		Grocy.WakeLock.NoSleepJsIntance.disable();
	}
}

// Handle "Keep screen on while displaying a fullscreen-card" when the body class "fullscreen-card" has changed
new MutationObserver(function(mutations)
{
	if (BoolVal(Grocy.UserSettings.keep_screen_on_when_fullscreen_card) && !BoolVal(Grocy.UserSettings.keep_screen_on))
	{
		mutations.forEach(function(mutation)
		{
			if (mutation.attributeName === "class")
			{
				var attributeValue = $(mutation.target).prop(mutation.attributeName);
				if (attributeValue.contains("fullscreen-card"))
				{
					Grocy.WakeLock.Enable();
				}
				else
				{
					Grocy.WakeLock.Disable();
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
	if (Grocy.WakeLock.InitDone === false && BoolVal(Grocy.UserSettings.keep_screen_on))
	{
		Grocy.WakeLock.Enable();
	}

	Grocy.WakeLock.InitDone = true;
});
