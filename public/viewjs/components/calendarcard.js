// Implements the CalendarCard widget (views/components/calendarcard.blade.php): renders a plain
// inline (non-input-bound) Tempus Dominus calendar into #calendar for at-a-glance date display.
// Public API: Init() - called once here on load, and available for a page that replaces the
// #calendar element and needs the widget built again.
Victual.Components.CalendarCard = {};

/** Builds (or rebuilds) the inline calendar widget on #calendar and shows it */
Victual.Components.CalendarCard.Init = function ()
{
	$('#calendar').datetimepicker(
		{
			format: 'L',
			buttons: {
				showToday: true,
				showClose: false
			},
			calendarWeeks: true,
			locale: moment.locale(),
			icons: {
				time: 'fa-solid fa-clock',
				date: 'fa-solid fa-calendar',
				up: 'fa-solid fa-arrow-up',
				down: 'fa-solid fa-arrow-down',
				previous: 'fa-solid fa-chevron-left',
				next: 'fa-solid fa-chevron-right',
				today: 'fa-solid fa-calendar-check',
				clear: 'fa-solid fa-trash-can',
				close: 'fa-solid fa-circle-xmark'
			},
			keepOpen: true,
			inline: true,
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

	// Always shown inline (never hidden behind an input click)
	$('#calendar').datetimepicker('show');
}
Victual.Components.CalendarCard.Init();
