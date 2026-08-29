// Database change polling: polls the API once a minute for the last database
// change timestamp and auto-reloads the page when another session/device changed
// something (opt-in via the user setting "auto_reload_on_db_change");
// also maintains the global idle time counter (Victual.IdleTime) used to avoid
// reloading while the user is actively working. Loaded on every page.

// Fetch the initial reference timestamp (GET /api/system/db-changed-time)
if (Victual.UserId !== -1)
{
	Victual.Api.Get('system/db-changed-time',
		function(result)
		{
			Victual.DatabaseChangedTime = moment(result.changed_time);
		},
		function(xhr)
		{
			console.error(xhr);
		}
	);
}

// Check if the database has changed once a minute
// If a change is detected, reload the current page, but only if already idling for at least 50 seconds,
// when there is no unsaved form data and when the user enabled auto reloading
setInterval(function()
{
	Victual.Api.Get('system/db-changed-time',
		function(result)
		{
			var newDbChangedTime = moment(result.changed_time);
			if (newDbChangedTime.isAfter(Victual.DatabaseChangedTime))
			{
				if (Victual.IdleTime >= 50)
				{
					if (BoolVal(Victual.UserSettings.auto_reload_on_db_change) && $("form.is-dirty").length === 0 && !$("body").hasClass("fullscreen-card"))
					{
						window.location.reload();
					}
				}

				Victual.DatabaseChangedTime = newDbChangedTime;
			}
		},
		function(xhr)
		{
			console.error(xhr);
		}
	);
}, 60000);

Victual.IdleTime = 0;
/** Resets the idle time counter to 0 (bound to any user interaction below). */
Victual.ResetIdleTime = function()
{
	Victual.IdleTime = 0;
}
window.onmousemove = Victual.ResetIdleTime;
window.onmousedown = Victual.ResetIdleTime;
window.onclick = Victual.ResetIdleTime;
window.onscroll = Victual.ResetIdleTime;
window.onkeypress = Victual.ResetIdleTime;

// Increase the idle time once every second
// On any interaction it will be reset to 0 (see above)
setInterval(function()
{
	Victual.IdleTime += 1;
}, 1000);

// Reflect the current setting in the settings page checkbox (if present on this page)
if (Victual.UserId !== -1 && BoolVal(Victual.UserSettings.auto_reload_on_db_change))
{
	$("#auto-reload-enabled").prop("checked", true);
}
