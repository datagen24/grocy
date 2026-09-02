// Powers the tasks list view (tasks.blade.php): table listing with status/category/user
// filters, marking tasks done/undone, deletion, the done-tasks toggle, and the due-today/
// due-soon/overdue summary widgets.
var tasksTable = $('#tasks-table').DataTable({
	'order': [[2, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ "type": "html", "targets": 2 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#tasks-table tbody').removeClass("d-none");
tasksTable.columns.adjust().draw();

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	tasksTable.search(value).draw();
}, Victual.FormFocusDelay));

// Status filter dropdown (e.g. "due soon"/"overdue"), matched against the hidden
// status-info column (index 5)
$("#status-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	tasksTable.column(tasksTable.colReorder.transpose(5)).search(value).draw();
});

// Assigned-user filter, matched against the user column (index 4). The selected name is
// anchored (and regex-escaped) so a user whose name is a substring of another user's name
// does not match both.
$("#user-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}
	else
	{
		value = "^" + $.fn.dataTable.util.escapeRegex(value) + "$";
	}

	tasksTable.column(tasksTable.colReorder.transpose(4)).search(value, true, false).draw();
});

// Category filter, matched against the category column (index 3)
$("#category-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	tasksTable.column(tasksTable.colReorder.transpose(3)).search(value).draw();
});

// Resets all filters (search/status/category/user)
$("#clear-filter-button").on("click", function ()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#category-filter").val("all");
	$("#search").trigger("keyup");
	$("#status-filter").trigger("change");
	$("#category-filter").trigger("change");
	$("#user-filter").val("all");
	$("#show-done-tasks").trigger('checked', false);
});

// Clicking a summary widget (due today/due soon/overdue) applies that status as the filter
$(".status-filter-message").on("click", function ()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Marks a task as completed (POST tasks/{id}/complete); either removes the row
// (default, done tasks hidden) or strikes it through in place (when "show done" is on)
$(document).on('click', '.do-task-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var taskId = $(e.currentTarget).attr('data-task-id');
	var taskName = $(e.currentTarget).attr('data-task-name');
	var doneTime = moment().format('YYYY-MM-DD HH:mm:ss');

	Victual.Api.Post('tasks/' + taskId + '/complete', { 'done_time': doneTime },
		function ()
		{
			if (!$("#show-done-tasks").is(":checked"))
			{
				animateCSS("#task-" + taskId + "-row", "fadeOut", function ()
				{
					$("#task-" + taskId + "-row").remove();
				});
			}
			else
			{
				$('#task-' + taskId + '-row').addClass("text-muted");
				$('#task-' + taskId + '-name').addClass("text-strike-through");
				$('.do-task-button[data-task-id="' + taskId + '"]').addClass("disabled");
			}

			Victual.FrontendHelpers.EndUiBusy();
			toastr.success(__t('Marked task %s as completed on %s', taskName, doneTime));
			RefreshContextualTimeago("#task-" + taskId + "-row");
			RefreshStatistics();
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			console.error(xhr);
		}
	);
});

// Reverts a completed task back to open (POST tasks/{id}/undo); reloads the whole page
// since the row's markup needs to change back to its "open" form
$(document).on('click', '.undo-task-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var taskId = $(e.currentTarget).attr('data-task-id');
	var taskName = $(e.currentTarget).attr('data-task-name');

	Victual.Api.Post('tasks/' + taskId + '/undo', {},
		function ()
		{
			window.location.reload();
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			console.error(xhr);
		}
	);
});

// Deletes a task (DELETE objects/tasks/{id}) after confirmation, fading the row out
$(document).on('click', '.delete-task-button', function (e)
{
	e.preventDefault();

	var objectName = $(e.currentTarget).attr('data-task-name');
	var objectId = $(e.currentTarget).attr('data-task-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete task "%s"?', objectName),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function (result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/tasks/' + objectId, {},
					function (result)
					{
						animateCSS("#task-" + objectId + "-row", "fadeOut", function ()
						{
							$("#task-" + objectId + "-row").remove();
						});
					},
					function (xhr)
					{
						console.error(xhr);
					}
				);
			}
		}
	});
});

// Toggling "show done tasks" reloads the page with/without the include_done query param
$("#show-done-tasks").change(function ()
{
	if (this.checked)
	{
		window.location.href = U('/tasks?include_done');
	}
	else
	{
		window.location.href = U('/tasks');
	}
});

// Reflect the current include_done state onto the checkbox on load
if (GetUriParam('include_done'))
{
	$("#show-done-tasks").prop('checked', true);
}

/**
 * Refreshes the due-today/due-soon/overdue summary widgets by fetching all tasks (GET
 * tasks) and bucketing them by due_date relative to today and the configured "next X
 * days" threshold. Called on load and after marking a task done.
 */
function RefreshStatistics()
{
	var nextXDays = $("#info-due-soon-tasks").data("next-x-days");
	Victual.Api.Get('tasks',
		function (result)
		{
			var dueTodayCount = 0;
			var dueSoonCount = 0;
			var overdueCount = 0;
			var overdueThreshold = moment().subtract(1, "days").endOf("day");
			var nextXDaysThreshold = moment().endOf("day").add(nextXDays, "days");
			var todayThreshold = moment().endOf("day");

			result.forEach(element =>
			{
				if (element.due_date)
				{
					var date = moment(element.due_date + " 23:59:59").endOf("day");

					if (date.isSameOrBefore(overdueThreshold))
					{
						overdueCount++;
					}
					else if (date.isSameOrBefore(todayThreshold))
					{
						dueTodayCount++;
						dueSoonCount++;
					}
					else if (date.isSameOrBefore(nextXDaysThreshold))
					{
						dueSoonCount++;
					}
				}
			});

			$("#info-due-today-tasks").html('<span class="d-block d-md-none">' + dueTodayCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueTodayCount, '%s task is due to be done today', '%s tasks are due to be done today'));
			$("#info-due-soon-tasks").html('<span class="d-block d-md-none">' + dueSoonCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueSoonCount, '%s task is due to be done', '%s tasks are due to be done') + ' ' + __n(nextXDays, 'within the next day', 'within the next %s days'));
			$("#info-overdue-tasks").html('<span class="d-block d-md-none">' + overdueCount + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(overdueCount, '%s task is overdue to be done', '%s tasks are overdue to be done'));
		},
		function (xhr)
		{
			console.error(xhr);
		}
	);
}

RefreshStatistics();

// Apply filters (there are maybe some set when a task was just edited)
$("#search").trigger("keyup");
$("#status-filter").trigger("change");
$("#category-filter").trigger("change");
