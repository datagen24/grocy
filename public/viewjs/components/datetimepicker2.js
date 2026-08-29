// Implements the DateTimePicker2 widget (views/components/datetimepicker2.blade.php): a second,
// independently-scoped instance of the DateTimePicker widget (see datetimepicker.js for the
// full behavior description), bound to ".datetimepicker2 input" instead - used on views that
// need two date/time pickers on the same page (e.g. a date range) without id/class collisions.
// Public API: GetInputElement/GetValue/SetValue/Clear/ChangeFormat/Init.
Victual.Components.DateTimePicker2 = {};

/** @returns {jQuery} The picker's underlying date/time text input */
Victual.Components.DateTimePicker2.GetInputElement = function()
{
	return $('.datetimepicker2').find('input').not(".form-check-input");
}

/** @returns {string} The current input value, formatted per the input's configured "format" */
Victual.Components.DateTimePicker2.GetValue = function()
{
	return Victual.Components.DateTimePicker2.GetInputElement().val();
}

/** Sets the input value directly and un-checks the shortcut checkbox if it no longer applies */
Victual.Components.DateTimePicker2.SetValue = function(value, inputElement = Victual.Components.DateTimePicker2.GetInputElement())
{
	// "Click" the shortcut checkbox when the desired value is
	// not the shortcut value and it is currently set
	var shortcutValue = $("#datetimepicker2-shortcut").data("datetimepicker-shortcut-value");
	if (value != shortcutValue && $("#datetimepicker2-shortcut").is(":checked"))
	{
		$("#datetimepicker2-shortcut").click();
	}
	inputElement.val(value);
	inputElement.keyup();
}

/** Re-inits the underlying picker widget and empties the input/shortcut/timeago display */
Victual.Components.DateTimePicker2.Clear = function()
{
	Victual.Components.DateTimePicker2.Init(true);

	Victual.Components.DateTimePicker2.GetInputElement().val("");

	// "Click" the shortcut checkbox when the desired value is
	// not the shortcut value and it is currently set
	value = "";
	var shortcutValue = $("#datetimepicker2-shortcut").data("datetimepicker2-shortcut-value");
	if (value != shortcutValue && $("#datetimepicker2-shortcut").is(":checked"))
	{
		$("#datetimepicker2-shortcut").click();
	}

	$('#datetimepicker2-timeago').text('');
}

/** Destroys and re-inits the picker with a new moment.js display/parse format (e.g. date-only vs date+time) */
Victual.Components.DateTimePicker2.ChangeFormat = function(format)
{
	$(".datetimepicker2").datetimepicker("destroy");
	Victual.Components.DateTimePicker2.GetInputElement().data("format", format);
	Victual.Components.DateTimePicker2.Init();

	if (format == "YYYY-MM-DD")
	{
		Victual.Components.DateTimePicker2.GetInputElement().addClass("date-only-datetimepicker2");
	}
	else
	{
		Victual.Components.DateTimePicker2.GetInputElement().removeClass("date-only-datetimepicker2");
	}
}

// Determine the initial date shown when opening the picker, from the template's
// data-init-with-now / data-init-value attributes on the input
var startDate = null;
if (Victual.Components.DateTimePicker2.GetInputElement().data('init-with-now') === true)
{
	startDate = moment().format(Victual.Components.DateTimePicker2.GetInputElement().data('format'));
}
if (Victual.Components.DateTimePicker2.GetInputElement().data('init-value').length > 0)
{
	startDate = moment(Victual.Components.DateTimePicker2.GetInputElement().data('init-value')).format(Victual.Components.DateTimePicker2.GetInputElement().data('format'));
}

// data-limit-end-to-now caps the calendar (and later validation) to not go beyond "now"
var limitDate = moment('2999-12-31 23:59:59');
if (Victual.Components.DateTimePicker2.GetInputElement().data('limit-end-to-now') === true)
{
	limitDate = moment();
}

/**
 * (Re-)initializes the Tempus Dominus widget on every ".datetimepicker2" input with the format/
 * limits/icons configured via data attributes. Idempotent unless reInit is set, which first
 * destroys any existing instance (used by ChangeFormat/Clear).
 * @param {boolean} [reInit=false] Destroy an existing picker instance before initializing.
 */
Victual.Components.DateTimePicker2.Init = function(reInit = false)
{
	if (reInit)
	{
		$(".datetimepicker2").datetimepicker("destroy");
	}

	$(".datetimepicker2").each(function()
	{
		$(this).datetimepicker(
			{
				format: $(this).find("input").data('format'),
				buttons: {
					showToday: Victual.Components.DateTimePicker2.GetInputElement().data('limit-end-to-now') !== true,
					showClose: true
				},
				calendarWeeks: Victual.CalendarShowWeekNumbers,
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
Victual.Components.DateTimePicker2.Init();

// Core typed-shorthand handling: interprets special input values/arrow-key combos as relative
// date edits, then re-validates the resulting value (required/limit-end-to-now/limit-start-to-now)
Victual.Components.DateTimePicker2.GetInputElement().on('keyup', function(e)
{
	$('.datetimepicker2').datetimepicker('hide');

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
		Victual.Components.DateTimePicker2.SetValue(moment(new Date(), format, true).format(format), inputElement);
		nextInputElement.focus();
	}
	else if (value === 'x' || value === 'X') // Shorthand for never overdue
	{
		Victual.Components.DateTimePicker2.SetValue(moment('2999-12-31 23:59:59').format(format), inputElement);
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
				Victual.Components.DateTimePicker2.SetValue(moment().add(n, "days").format(format));
				nextInputElement.focus();
			}
			else if (lastCharacter == "m")
			{
				Victual.Components.DateTimePicker2.SetValue(moment().add(n, "months").format(format));
				nextInputElement.focus();
			}
			else if (lastCharacter == "y")
			{
				Victual.Components.DateTimePicker2.SetValue(moment().add(n, "years").format(format));
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
		Victual.Components.DateTimePicker2.SetValue(date.format(format), inputElement);
		nextInputElement.focus();
	}
	else if (value.length === 8 && $.isNumeric(value)) // Shorthand for YYYYMMDD
	{
		Victual.Components.DateTimePicker2.SetValue(value.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3'), inputElement);
		nextInputElement.focus();
	}
	else if (value.length === 7 && $.isNumeric(value.substring(0, 6)) && (value.substring(6, 7).toLowerCase() === "e" || value.substring(6, 7) === "+")) // Shorthand for YYYYMM[e/+]
	{
		var date = moment(value.substring(0, 4) + "-" + value.substring(4, 6) + "-01").endOf("month");
		Victual.Components.DateTimePicker2.SetValue(date.format(format), inputElement);
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
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(-1, 'months').format(format), inputElement);
				}
				else if (e.keyCode === 40) // Down
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(1, 'months').format(format), inputElement);
				}
				else if (e.keyCode === 37) // Left
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(-1, 'years').format(format), inputElement);
				}
				else if (e.keyCode === 39) // Right
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(1, 'years').format(format), inputElement);
				}
			}
			else
			{
				// WITHOUT shift modifier key

				if (e.keyCode === 38) // Up
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(-1, 'days').format(format), inputElement);
				}
				else if (e.keyCode === 40) // Down
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(1, 'days').format(format), inputElement);
				}
				else if (e.keyCode === 37) // Left
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(-1, 'weeks').format(format), inputElement);
				}
				else if (e.keyCode === 39) // Right
				{
					Victual.Components.DateTimePicker2.SetValue(dateObj.add(1, 'weeks').format(format), inputElement);
				}
			}
		}
	}

	$('#datetimepicker2-timeago').attr("datetime", Victual.Components.DateTimePicker2.GetValue());
	RefreshContextualTimeago(".datetimepicker2-wrapper");

	// Custom validation: invalid/unparsable dates, and dates violating limit-end-to-now /
	// limit-start-to-now are flagged via the input's native validity API
	value = Victual.Components.DateTimePicker2.GetValue();
	dateObj = moment(value, format, true);
	var element = Victual.Components.DateTimePicker2.GetInputElement()[0];
	if (!dateObj.isValid())
	{
		if ($(element).hasAttr("required"))
		{
			element.setCustomValidity("error");
		}
	}
	else
	{
		if (Victual.Components.DateTimePicker2.GetInputElement().data('limit-end-to-now') === true && dateObj.isAfter(moment()))
		{
			element.setCustomValidity("error");
		}
		else if (Victual.Components.DateTimePicker2.GetInputElement().data('limit-start-to-now') === true && dateObj.isBefore(moment()))
		{
			element.setCustomValidity("error");
		}
		else
		{
			element.setCustomValidity("");
		}

		// data-earlier-than-limit shows an informational (non-blocking) hint when the chosen
		// date is earlier than a given reference date (e.g. a related field's value)
		var earlierThanLimit = Victual.Components.DateTimePicker2.GetInputElement().data("earlier-than-limit");
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
	var shortcutValue = $("#datetimepicker2-shortcut").data("datetimepicker2-shortcut-value");
	if (value == shortcutValue && !$("#datetimepicker2-shortcut").is(":checked"))
	{
		$("#datetimepicker2-shortcut").click();
	}
});

// Keeps the contextual "timeago" display in sync with manual edits
Victual.Components.DateTimePicker2.GetInputElement().on('input', function(e)
{
	$('#datetimepicker2-timeago').attr("datetime", Victual.Components.DateTimePicker2.GetValue());
	RefreshContextualTimeago(".datetimepicker2-wrapper");
});

// Calendar widget updated its value programmatically -> propagate to our own 'input' handling
$('.datetimepicker2').on('update.datetimepicker', function(e)
{
	Victual.Components.DateTimePicker2.GetInputElement().trigger('input');
});

// Calendar closed -> re-run all of our change handling (input/change/keypress/keyup) once more
$('.datetimepicker2').on('hide.datetimepicker', function(e)
{
	Victual.Components.DateTimePicker2.GetInputElement().trigger('input');
	Victual.Components.DateTimePicker2.GetInputElement().trigger('change');
	Victual.Components.DateTimePicker2.GetInputElement().trigger('keypress');
	Victual.Components.DateTimePicker2.GetInputElement().trigger('keyup');
});

// The "shortcut" checkbox snaps the picker to (and locks it at) a preconfigured value
// (data-datetimepicker2-shortcut-value), e.g. "never expires"
$("#datetimepicker2-shortcut").on("click", function()
{
	if (this.checked)
	{
		var value = $("#datetimepicker2-shortcut").data("datetimepicker2-shortcut-value");
		Victual.Components.DateTimePicker2.SetValue(value);
		Victual.Components.DateTimePicker2.GetInputElement().attr("readonly", "");
		$(Victual.Components.DateTimePicker2.GetInputElement().data('next-input-selector')).focus();
	}
	else
	{
		Victual.Components.DateTimePicker2.SetValue("");
		Victual.Components.DateTimePicker2.GetInputElement().removeAttr("readonly");
		Victual.Components.DateTimePicker2.GetInputElement().focus();
	}

	Victual.Components.DateTimePicker2.GetInputElement().trigger('input');
	Victual.Components.DateTimePicker2.GetInputElement().trigger('change');
	Victual.Components.DateTimePicker2.GetInputElement().trigger('keypress');
});
