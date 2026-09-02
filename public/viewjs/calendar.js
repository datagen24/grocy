// View script for the calendar page (views/calendar.blade.php):
// FullCalendar setup fed by the server-rendered fullcalendarEventSources global,
// iCal sharing link retrieval via GET /api/calendar/ical/sharing-link and event color configuration modal

// First day of week comes from the Victual.CalendarFirstDayOfWeek user setting (empty = locale default)
var firstDay = null;
if (Victual.CalendarFirstDayOfWeek)
{
	firstDay = Number.parseInt(Victual.CalendarFirstDayOfWeek);
}

// FullCalendar setup; clicking an event navigates to its associated Victual page (info.link)
var calendar = $("#calendar").fullCalendar({
	"themeSystem": "bootstrap4",
	"header": {
		"left": "month,agendaWeek,agendaDay,listWeek",
		"center": "title",
		"right": "prev,today,next"
	},
	"weekNumbers": Victual.CalendarShowWeekNumbers,
	"defaultView": ($(window).width() < 768) ? "agendaDay" : "month",
	"firstDay": firstDay,
	"eventLimit": false,
	"height": "auto",
	"eventSources": fullcalendarEventSources,
	"eventClick": function(info)
	{
		location.href = info.link;
	},
	"timeFormat": "HH:mm"
});

// Show the public iCal sharing URL (GET /api/calendar/ical/sharing-link) in a dialog including a QR code
$("#ical-button").on("click", function(e)
{
	e.preventDefault();

	Victual.Api.Get('calendar/ical/sharing-link',
		function(result)
		{
			bootbox.alert({
				title: __t('Share/Integrate calendar (iCal)'),
				message: __t('Use the following (public) URL to share or integrate the calendar in iCal format') + '<input type="text" class="form-control form-control-sm mt-2 easy-link-copy-textbox" value="' + result.url + '"><p class="text-center mt-4">'
					+ QrCodeImgHtml(result.url) + "</p>",
				closeButton: false
			});
		}
	);
});

$(window).one("resize", function()
{
	// Automatically switch the calendar to "basicDay" view on small screens
	// and to "month" otherwise
	if ($(window).width() < 768)
	{
		calendar.fullCalendar("changeView", "agendaDay");
	}
	else
	{
		calendar.fullCalendar("changeView", "month");
	}
});

// Event color configuration modal; reload the page on close so changed colors take effect
$("#configure-colors-button").on("click", function(e)
{
	e.preventDefault();

	$("#configure-colors-modal").modal("show");
});

$("#configure-colors-modal").on("hidden.bs.modal", function(e)
{
	window.location.href = U('/calendar');
})
