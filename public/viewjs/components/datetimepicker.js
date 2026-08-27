// Implements the (primary) DateTimePicker widget (views/components/datetimepicker.blade.php):
// wraps a Tempus Dominus datetimepicker bound to ".datetimepicker input" with typed shorthand
// parsing (arrow keys to step, "x"/"X" for "never overdue", "+/-n[d/m/y]" relative offsets,
// "MMDD"/"YYYYMMDD"/"YYYYMM[e/+]" quick dates) and an optional "shortcut" checkbox
// (#datetimepicker-shortcut) that snaps to a fixed configured value.
// A second, independently-scoped instance of this same widget exists as DateTimePicker2 (see
// datetimepicker2.js) for views needing two pickers at once (e.g. a date range).
// Public API: GetInputElement/GetValue/SetValue/Clear/ChangeFormat/Init.
Grocy.Components.DateTimePicker = {};

/** @returns {jQuery} The picker's underlying date/time text input */
Grocy.Components.DateTimePicker.GetInputElement = function()
{
	return $('.datetimepicker').find('input').not(".form-check-input");
}

/** @returns {string} The current input value, formatted per the input's configured "format" */
Grocy.Components.DateTimePicker.GetValue = function()
{
	return Grocy.Components.DateTimePicker.GetInputElement().val();
}

/** Sets the input value directly and un-checks the shortcut checkbox if it no longer applies */
Grocy.Components.DateTimePicker.SetValue = function(value, inputElement = Grocy.Components.DateTimePicker.GetInputElement())
{
	// "Click" the shortcut checkbox when the desired value is
	// not the shortcut value and it is currently set
	var shortcutValue = $("#datetimepicker-shortcut").data("datetimepicker-shortcut-value");
	if (value != shortcutValue && $("#datetimepicker-shortcut").is(":checked"))
	{
		$("#datetimepicker-shortcut").click();
	}
	inputElement.val(value);
	inputElement.keyup();
}

/** Re-inits the underlying picker widget and empties the input/shortcut/timeago display */
Grocy.Components.DateTimePicker.Clear = function()
{
	Grocy.Components.DateTimePicker.Init(true);

	Grocy.Components.DateTimePicker.GetInputElement().val("");

	// "Click" the shortcut checkbox when the desired value is
	// not the shortcut value and it is currently set
	value = "";
	var shortcutValue = $("#datetimepicker-shortcut").data("datetimepicker-shortcut-value");
	if (value != shortcutValue && $("#datetimepicker-shortcut").is(":checked"))
	{
		$("#datetimepicker-shortcut").click();
	}

	$('#datetimepicker-timeago').text('');
}

/** Destroys and re-inits the picker with a new moment.js display/parse format (e.g. date-only vs date+time) */
Grocy.Components.DateTimePicker.ChangeFormat = function(format)
{
	$(".datetimepicker").datetimepicker("destroy");
	Grocy.Components.DateTimePicker.GetInputElement().data("format", format);
	Grocy.Components.DateTimePicker.Init();

	if (format == "YYYY-MM-DD")
	{
		Grocy.Components.DateTimePicker.GetInputElement().addClass("date-only-datetimepicker");
	}
	else
	{
		Grocy.Components.DateTimePicker.GetInputElement().removeClass("date-only-datetimepicker");
	}
}

// Determine the initial date shown when opening the picker, from the template's
// data-init-with-now / data-init-value attributes on the input
var startDate = null;
if (Grocy.Components.DateTimePicker.GetInputElement().data('init-with-now') === true)
{
	startDate = moment().format(Grocy.Components.DateTimePicker.GetInputElement().data('format'));
}
if (Grocy.Components.DateTimePicker.GetInputElement().data('init-value').length > 0)
{
	startDate = moment(Grocy.Components.DateTimePicker.GetInputElement().data('init-value')).format(Grocy.Components.DateTimePicker.GetInputElement().data('format'));
}

// data-limit-end-to-now caps the calendar (and later validation) to not go beyond "now"
var limitDate = moment('2999-12-31 23:59:59');
if (Grocy.Components.DateTimePicker.GetInputElement().data('limit-end-to-now') === true)
{
	limitDate = moment();
}

/**
 * (Re-)initializes the Tempus Dominus widget on every ".datetimepicker" input with the format/
 * limits/icons configured via data attributes. Idempotent unless reInit is set, which first
 * destroys any existing instance (used by ChangeFormat/Clear).
 * @param {boolean} [reInit=false] Destroy an existing picker instance before initializing.
 */
Grocy.Components.DateTimePicker.Init = function(reInit = false)
{
	if (reInit)
	{
		$(".datetimepicker").datetimepicker("destroy");
	}

	$(".datetimepicker").each(function()
	{
		$(this).datetimepicker(
			{
				format: $(this).find("input").data('format'),
				buttons: {
					showToday: Grocy.Components.DateTimePicker.GetInputElement().data('limit-end-to-now') !== true,
					showClose: true
				},
				calendarWeeks: Grocy.CalendarShowWeekNumbers,
				maxDate: limitDate,
				locale: moment.locale(),
				defaultDate: startDate,
				useCurrent: false,
				icons: {
					time: 'fa-solid fa-clock',
					date: 'fa-solid fa-calendar',
					up: 'fa-solid fa-arrow-up',
					down: 'fa-solid fa-arrow-down',
					previous: 'fa-solid fa-chevron-left',
					next: 'fa-solid fa-chevron-right',
					today: 'fa-solid fa-calendar-day',
					clear: 'fa-solid fa-trash-can',
					close: 'fa-solid fa-check'
				},
				sideBySide: true,
				keyBinds: {
					up: function(widget) { },
					down: function(widget) { },
					'control up': function(widget) { },
					'control down': function(widget) { },
					left: function(widget) { },
					right: function(widget) { },
					pageUp: function(widget) { },
					pageDown: function(widget) { },
					enter: function(widget) { },
					escape: function(widget) { },
					'control space': function(widget) { },
					t: function(widget) { },
					'delete': function(widget) { }
				}
			});
	});
}
Grocy.Components.DateTimePicker.Init();

// Core typed-shorthand handling: interprets special input values/arrow-key combos as relative
// date edits, then re-validates the resulting value (required/limit-end-to-now/limit-start-to-now)
Grocy.Components.DateTimePicker.GetInputElement().on('keyup', function(e)
{
	$('.datetimepicker').datetimepicker('hide');

	var inputElement = $(e.currentTarget)
	var value = inputElement.val();
	var format = inputElement.data('format');
	var nextInputElement = $(inputElement.data('next-input-selector'));

	if (!nextInputElement.is("input"))
	{
		nextInputElement = nextInputElement.find("input");
	}

	// If input is empty and any arrow key is pressed, set date to today
	if (value.length === 0 && (e.keyCode === 38 || e.keyCode === 40 || e.keyCode === 37 || e.keyCode === 39))
	{
		Grocy.Components.DateTimePicker.SetValue(moment(new Date(), format, true).format(format), inputElement);
		nextInputElement.focus();
	}
	else if (value === 'x' || value === 'X') // Shorthand for never overdue
	{
		Grocy.Components.DateTimePicker.SetValue(moment('2999-12-31 23:59:59').format(format), inputElement);
		nextInputElement.focus();
	}
	else if ((value.startsWith("+") || value.startsWith("-"))) // Shorthand for [+/-]n[d/m/y]
	{
		var lastCharacter = value.slice(-1).toLowerCase();

		if (lastCharacter == "d" || lastCharacter == "m" || lastCharacter == "y")
		{
			var n = Number.parseInt(value.substring(1, value.length - 1));
			if (value.startsWith("-"))
			{
				n = n * -1;
			}

			if (lastCharacter == "d")
			{
				Grocy.Components.DateTimePicker.SetValue(moment().add(n, "days").format(format));
				nextInputElement.focus();
			}
			else if (lastCharacter == "m")
			{
				Grocy.Components.DateTimePicker.SetValue(moment().add(n, "months").format(format));
				nextInputElement.focus();
			}
			else if (lastCharacter == "y")
			{
				Grocy.Components.DateTimePicker.SetValue(moment().add(n, "years").format(format));
				nextInputElement.focus();
			}
		}
	}
	else if (value.length === 4 && $.isNumeric(value) && Number.parseInt(value.substring(0, 2)) >= 1 && Number.parseInt(value.substring(0, 2)) <= 12) // Shorthand for MMDD
	{
		var date = moment((new Date()).getFullYear().toString() + value);
		if (date.isBefore(moment()))
		{
			date.add(1, "year");
		}
		Grocy.Components.DateTimePicker.SetValue(date.format(format), inputElement);
		nextInputElement.focus();
	}
	else if (value.length === 8 && $.isNumeric(value)) // Shorthand for YYYYMMDD
	{
		Grocy.Components.DateTimePicker.SetValue(value.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3'), inputElement);
		nextInputElement.focus();
	}
	else if (value.length === 7 && $.isNumeric(value.substring(0, 6)) && (value.substring(6, 7).toLowerCase() === "e" || value.substring(6, 7) === "+")) // Shorthand for YYYYMM[e/+]
	{
		var date = moment(value.substring(0, 4) + "-" + value.substring(4, 6) + "-01").endOf("month");
		Grocy.Components.DateTimePicker.SetValue(date.format(format), inputElement);
		nextInputElement.focus();
	}
	else
	{
		var dateObj = moment(value, format, true);
		if (dateObj.isValid())
		{
			if (e.shiftKey)
			{
				// WITH shift modifier key

				if (e.keyCode === 38) // Up
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(-1, 'months').format(format), inputElement);
				}
				else if (e.keyCode === 40) // Down
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(1, 'months').format(format), inputElement);
				}
				else if (e.keyCode === 37) // Left
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(-1, 'years').format(format), inputElement);
				}
				else if (e.keyCode === 39) // Right
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(1, 'years').format(format), inputElement);
				}
			}
			else
			{
				// WITHOUT shift modifier key

				if (e.keyCode === 38) // Up
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(-1, 'days').format(format), inputElement);
				}
				else if (e.keyCode === 40) // Down
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(1, 'days').format(format), inputElement);
				}
				else if (e.keyCode === 37) // Left
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(-1, 'weeks').format(format), inputElement);
				}
				else if (e.keyCode === 39) // Right
				{
					Grocy.Components.DateTimePicker.SetValue(dateObj.add(1, 'weeks').format(format), inputElement);
				}
			}
		}
	}

	$('#datetimepicker-timeago').attr("datetime", Grocy.Components.DateTimePicker.GetValue());
	RefreshContextualTimeago(".datetimepicker-wrapper");

	// Custom validation: invalid/unparsable dates, and dates violating limit-end-to-now /
	// limit-start-to-now are flagged via the input's native validity API
	value = Grocy.Components.DateTimePicker.GetValue();
	dateObj = moment(value, format, true);
	var element = Grocy.Components.DateTimePicker.GetInputElement()[0];
	if (!dateObj.isValid())
	{
		if ($(element).hasAttr("required"))
		{
			element.setCustomValidity("error");
		}
	}
	else
	{
		if (Grocy.Components.DateTimePicker.GetInputElement().data('limit-end-to-now') === true && dateObj.isAfter(moment()))
		{
			element.setCustomValidity("error");
		}
		else if (Grocy.Components.DateTimePicker.GetInputElement().data('limit-start-to-now') === true && dateObj.isBefore(moment()))
		{
			element.setCustomValidity("error");
		}
		else
		{
			element.setCustomValidity("");
		}

		// data-earlier-than-limit shows an informational (non-blocking) hint when the chosen
		// date is earlier than a given reference date (e.g. a related field's value)
		var earlierThanLimit = Grocy.Components.DateTimePicker.GetInputElement().data("earlier-than-limit");
		if (earlierThanLimit)
		{
			if (moment(value).isBefore(moment(earlierThanLimit)))
			{
				$("#datetimepicker-earlier-than-info").removeClass("d-none");
			}
			else
			{
				$("#datetimepicker-earlier-than-info").addClass("d-none");
			}
		}
	}

	// "Click" the shortcut checkbox when the shortcut value was
	// entered manually and it is currently not set
	var shortcutValue = $("#datetimepicker-shortcut").data("datetimepicker-shortcut-value");
	if (value == shortcutValue && !$("#datetimepicker-shortcut").is(":checked"))
	{
		$("#datetimepicker-shortcut").click();
	}
});

// Keeps the contextual "timeago" display in sync with manual edits
Grocy.Components.DateTimePicker.GetInputElement().on('input', function(e)
{
	$('#datetimepicker-timeago').attr("datetime", Grocy.Components.DateTimePicker.GetValue());
	RefreshContextualTimeago(".datetimepicker-wrapper");
});

// Calendar widget updated its value programmatically -> propagate to our own 'input' handling
$('.datetimepicker').on('update.datetimepicker', function(e)
{
	Grocy.Components.DateTimePicker.GetInputElement().trigger('input');
});

// Calendar closed -> re-run all of our change handling (input/change/keypress/keyup) once more
$('.datetimepicker').on('hide.datetimepicker', function(e)
{
	Grocy.Components.DateTimePicker.GetInputElement().trigger('input');
	Grocy.Components.DateTimePicker.GetInputElement().trigger('change');
	Grocy.Components.DateTimePicker.GetInputElement().trigger('keypress');
	Grocy.Components.DateTimePicker.GetInputElement().trigger('keyup');
});

// The "shortcut" checkbox snaps the picker to (and locks it at) a preconfigured value
// (data-datetimepicker-shortcut-value), e.g. "never expires"
$("#datetimepicker-shortcut").on("click", function()
{
	if (this.checked)
	{
		var value = $("#datetimepicker-shortcut").data("datetimepicker-shortcut-value");
		Grocy.Components.DateTimePicker.SetValue(value);
		Grocy.Components.DateTimePicker.GetInputElement().attr("readonly", "");
		$(Grocy.Components.DateTimePicker.GetInputElement().data('next-input-selector')).focus();
	}
	else
	{
		Grocy.Components.DateTimePicker.SetValue("");
		Grocy.Components.DateTimePicker.GetInputElement().removeAttr("readonly");
		Grocy.Components.DateTimePicker.GetInputElement().focus();
	}

	Grocy.Components.DateTimePicker.GetInputElement().trigger('input');
	Grocy.Components.DateTimePicker.GetInputElement().trigger('change');
	Grocy.Components.DateTimePicker.GetInputElement().trigger('keypress');
});
