// Shared DataTables setup: global defaults for all tables (state saving via user
// settings, localization, column reordering, row grouping incl. collapse/expand),
// accent neutral searching/sorting and the "table options" dialog for column
// visibility / grouping. Loaded on every page after grocy.js.
//
// Table state is persisted per table as the user settings
// "datatables_state_<tableId>" and "datatables_rowGroup_<tableId>" (JSON strings).

// Default DataTables initialisation settings
var collapsedGroups = {}; // Per group name (data-name) collapsed state of row groups (not persisted)
$.extend(true, $.fn.dataTable.defaults, {
	'paginate': false,
	'deferRender': true,
	'language': IsJsonString(__t('datatables_localization')) ? JSON.parse(__t('datatables_localization')) : {},
	'scrollY': false,
	'scrollX': true,
	'colReorder': true,
	'stateSave': true,
	'stateDuration': 0,
	'stateSaveParams': function (settings, data)
	{
		// Never persist search terms - a reloaded page should start unfiltered
		data.search.search = "";

		data.columns.forEach(column =>
		{
			column.search.search = "";
		});
	},
	'stateSaveCallback': function (settings, data)
	{
		// Persists the table state as user setting "datatables_state_<tableId>"
		var settingKey = 'datatables_state_' + settings.sTableId;

		if ($.isEmptyObject(data))
		{
			// state.clear() was called (resetting table layout)
			Grocy.FrontendHelpers.DeleteUserSetting(settingKey, true);
		}
		else
		{
			// Don't save when the state data hasn't actually changed
			if (Grocy.UserSettings[settingKey] !== undefined)
			{
				var data1 = JSON.parse(Grocy.UserSettings[settingKey]);
				delete data1.time;
				delete data1.childRows;

				var data2 = Object.assign({}, data); // Clone `data` without reference
				delete data2.time;
				delete data2.childRows;

				if (JSON.stringify(data1) == JSON.stringify(data2))
				{
					return;
				}
			}

			Grocy.FrontendHelpers.SaveUserSetting(settingKey, JSON.stringify(data));
		}
	},
	'stateLoadCallback': function (settings, data)
	{
		// Loads the table state from user setting "datatables_state_<tableId>"
		var settingKey = 'datatables_state_' + settings.sTableId;

		if (Grocy.UserSettings[settingKey] == undefined)
		{
			return null;
		}
		else
		{
			return JSON.parse(Grocy.UserSettings[settingKey]);
		}
	},
	'preDrawCallback': function (settings)
	{
		// Currently it is not possible to save the state of rowGroup via saveState events
		// => Restore it here from user setting "datatables_rowGroup_<tableId>" before each draw
		var api = new $.fn.dataTable.Api(settings);
		if (typeof api.rowGroup === "function")
		{
			var settingKey = 'datatables_rowGroup_' + settings.sTableId;
			if (Grocy.UserSettings[settingKey] !== undefined)
			{
				var rowGroup = JSON.parse(Grocy.UserSettings[settingKey]);

				// The draw event is called often therefore we have to check if it's really necessary
				if (rowGroup.enable !== api.rowGroup().enabled()
					|| ("dataSrc" in rowGroup && rowGroup.dataSrc !== api.rowGroup().dataSrc()))
				{

					api.rowGroup().enable(rowGroup.enable);

					if ("dataSrc" in rowGroup)
					{
						api.rowGroup().dataSrc(rowGroup.dataSrc);

						// Apply fixed order for group column
						api.order.fixed({
							pre: [rowGroup.dataSrc, 'asc']
						});
					}
					else
					{
						// Remove fixed order
						api.order.fixed({});
					}
				}
			}
		}
	},
	'columnDefs': [
		{ type: 'string', targets: '_all' }
	],
	'rowGroup': {
		enable: false,
		// Renders the group header row (with collapse caret) and hides the
		// rows of collapsed groups (see collapsedGroups above)
		startRender: function (rows, group)
		{
			var collapsed = !!collapsedGroups[group];
			var toggleClass = collapsed ? "fa-caret-right" : "fa-caret-down";

			rows.nodes().each(function (row)
			{
				row.style.display = collapsed ? "none" : "";
			});

			return $("<tr/>")
				.append('<td colspan="' + rows.columns()[0].length + '">' + group + ' <span class="fa fa-fw d-print-none ' + toggleClass + '"/></td>')
				.attr("data-name", group)
				.toggleClass("collapsed", collapsed);
		}
	}
});
// Toggle collapse/expand when a group header row is clicked
$(document).on("click", "tr.dtrg-group", function ()
{
	var name = $(this).data('name');
	collapsedGroups[name] = !collapsedGroups[name];
	$("table").DataTable().draw();
});
$.fn.dataTable.ext.type.order["custom-sort-pre"] = function (data)
{
	// Workaround for https://github.com/DataTables/ColReorder/issues/85
	//
	// Custom sorting can normally be provided by a "data-order" attribute on the <td> element,
	// however this causes issues when reordering such a column...
	//
	// This here is for a custom column type "custom-sort",
	// the custom order value needs to be provided in the first child (<span>) of the <td>

	return (Number.parseFloat($(data).get(0).innerText));
};

// Search and sort accent neutral (and for HTML columns on the plain text), see String.prototype.accentNeutralise
$.fn.dataTable.ext.type.search.string = function (s)
{
	return s.accentNeutralise();
};
$.fn.dataTable.ext.type.search.html = function (s)
{
	return s.stripHtml().accentNeutralise();
};
$.fn.dataTable.ext.type.order["string-pre"] = function (s)
{
	return s.accentNeutralise();
};
$.fn.dataTable.ext.type.order["html-pre"] = function (s)
{
	return s.stripHtml().accentNeutralise();
};

// Only allow the table body to overflow (for row dropdown menus) when the table
// fits into the scroll container - otherwise the horizontal scrollbar would be lost
$('.table').on('column-sizing.dt', function (e, settings)
{
	var dtScrollWidth = $('.dataTables_scroll').width();
	var tableWidth = $('.table').width() + 100; // Some extra padding, otherwise the scrollbar maybe only appears after a column is already completely out of the viewport

	if (dtScrollWidth < tableWidth)
	{
		$('.dataTables_scrollBody').addClass("no-force-overflow-visible");
		$('.dataTables_scrollBody').removeClass("force-overflow-visible");
	}
	else
	{
		$('.dataTables_scrollBody').removeClass("no-force-overflow-visible");
		$('.dataTables_scrollBody').addClass("force-overflow-visible");
	}
});
// Temporarily allow overflow while a row dropdown menu is open (so it's not cut off)
$(document).on("show.bs.dropdown", "td .dropdown", function (e)
{
	if ($('.dataTables_scrollBody').hasClass("no-force-overflow-visible"))
	{
		$('.dataTables_scrollBody').addClass("force-overflow-visible");
	}
});
$(document).on("hide.bs.dropdown", "td .dropdown", function (e)
{
	if ($('.dataTables_scrollBody').hasClass("no-force-overflow-visible"))
	{
		$('.dataTables_scrollBody').removeClass("force-overflow-visible");
	}
});

// "Table options" dialog: column visibility checkboxes and (when the table has
// rowGroup) "group by" radio buttons; the target table is referenced by the
// button's "data-table-selector" attribute
$(".change-table-columns-visibility-button").on("click", function (e)
{
	e.preventDefault();

	var dataTableSelector = $(e.currentTarget).attr("data-table-selector");
	var dataTable = $(dataTableSelector).DataTable();

	var columnCheckBoxesHtml = "";
	var rowGroupRadioBoxesHtml = "";

	var rowGroupDefined = typeof dataTable.rowGroup === "function";

	if (rowGroupDefined)
	{
		// "None" option (data-column-index="-1") to disable grouping
		var rowGroupChecked = (dataTable.rowGroup().enabled()) ? "" : "checked";
		rowGroupRadioBoxesHtml = ' \
			<div class="custom-control custom-radio custom-control-inline"> \
				<input ' + rowGroupChecked + ' class="custom-control-input change-table-columns-rowgroup-toggle" \
					type="radio" \
					name="column-rowgroup" \
					id="column-rowgroup-none" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="-1" \
				> \
				<label class="custom-control-label font-italic" \
					for="column-rowgroup-none">' + __t("None") + ' \
				</label > \
			</div>';
	}

	// Build one checkbox per visible-configurable column
	// (columns without title, "Hidden*" titles or a "d-none" header are skipped)
	dataTable.columns().every(function ()
	{
		var index = this.index();
		var indexForGrouping = index;
		var headerCell = $(this.header());
		var title = headerCell.text();
		var visible = this.visible();

		if (!title || title.trim().length == 0 || title.startsWith("Hidden") || headerCell.hasClass("d-none"))
		{
			return;
		}

		// A column can group by another (hidden) column via "data-shadow-rowgroup-column"
		var shadowColumnIndex = headerCell.attr("data-shadow-rowgroup-column");
		if (shadowColumnIndex)
		{
			indexForGrouping = shadowColumnIndex;
		}

		var checked = "checked";
		if (!visible)
		{
			checked = "";
		}

		columnCheckBoxesHtml += ' \
			<div class="custom-control custom-checkbox"> \
				<input ' + checked + ' class="form-check-input custom-control-input change-table-columns-visibility-toggle" \
					type="checkbox" \
					id="column-' + index.toString() + '" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="' + index.toString() + '" \
					value="1"> \
				<label class="form-check-label custom-control-label" \
					for="column-' + index.toString() + '">' + title + ' \
				</label> \
			</div>';

		// Grouping is only offered for columns with the header class "allow-grouping"
		if (rowGroupDefined && headerCell.hasClass("allow-grouping"))
		{
			var rowGroupChecked = "";
			if (dataTable.rowGroup().enabled() && dataTable.rowGroup().dataSrc() == index)
			{
				rowGroupChecked = "checked";
			}

			rowGroupRadioBoxesHtml += ' \
			<div class="custom-control custom-radio"> \
				<input ' + rowGroupChecked + ' class="custom-control-input change-table-columns-rowgroup-toggle" \
					type="radio" \
					name="column-rowgroup" \
					id="column-rowgroup-' + indexForGrouping.toString() + '" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="' + indexForGrouping.toString() + '" \
				> \
				<label class="custom-control-label" \
					for="column-rowgroup-' + indexForGrouping.toString() + '">' + title + ' \
				</label > \
			</div>';
		}
	});

	var message = '\
		<div class="text-center"> \
			<h5>' + __t('Table options') + '</h5> \
			<hr> \
			<h5 class="mb-0">' + __t('Hide/view columns') + '</h5> \
			<div class="text-left form-group"> \
				' + columnCheckBoxesHtml + ' \
			</div> \
		</div>';

	if (rowGroupDefined)
	{
		message += ' \
			<div class="text-center mt-1"> \
				<h5 class="pt-3 mb-0">' + __t('Group by') + '</h5> \
				<div class="text-left form-group"> \
					' + rowGroupRadioBoxesHtml + ' \
				</div> \
			</div>';
	}

	bootbox.dialog({
		message: message,
		size: 'small',
		backdrop: true,
		closeButton: false,
		buttons: {
			reset: {
				label: __t('Reset'),
				className: 'btn-outline-danger float-left responsive-button',
				callback: function ()
				{
					// "Reset" deletes the persisted state/rowGroup user settings after confirmation
					bootbox.confirm({
						message: __t("Are you sure you want to reset the table options?"),
						closeButton: false,
						buttons: {
							cancel: {
								label: 'No',
								className: 'btn-danger'
							},
							confirm: {
								label: 'Yes',
								className: 'btn-success'
							}
						},
						callback: function (result)
						{
							if (result)
							{
								var dataTable = $(dataTableSelector).DataTable();
								var tableId = dataTable.settings()[0].sTableId;

								// Delete rowgroup settings
								Grocy.FrontendHelpers.DeleteUserSetting('datatables_rowGroup_' + tableId);

								// Delete state settings
								dataTable.state.clear();
							}
							$(".modal").last().modal("hide");
						}
					});
				}
			},
			ok: {
				label: __t('OK'),
				className: 'btn-primary responsive-button',
				callback: function ()
				{
					$(".modal").last().modal("hide");
				}
			}
		}
	});
});

// Column visibility checkbox in the table options dialog
$(document).on("click", ".change-table-columns-visibility-toggle", function ()
{
	var dataTableSelector = $(this).attr("data-table-selector");
	var columnIndex = $(this).attr("data-column-index");
	var dataTable = $(dataTableSelector).DataTable();

	dataTable.columns(columnIndex).visible(this.checked);
});


// "Group by" radio button in the table options dialog: applies row grouping to the
// selected column (or disables it for index -1) and persists the choice as
// user setting "datatables_rowGroup_<tableId>"
$(document).on("click", ".change-table-columns-rowgroup-toggle", function ()
{
	var dataTableSelector = $(this).attr("data-table-selector");
	var columnIndex = $(this).attr("data-column-index");
	var dataTable = $(dataTableSelector).DataTable();
	var rowGroup;

	if (columnIndex == -1)
	{
		rowGroup = {
			enable: false
		};

		dataTable.rowGroup().enable(false);

		// Remove fixed order
		dataTable.order.fixed({});
	}
	else
	{
		rowGroup = {
			enable: true,
			dataSrc: columnIndex
		}

		dataTable.rowGroup().enable(true);
		dataTable.rowGroup().dataSrc(columnIndex);

		// Apply fixed order for group column
		dataTable.order.fixed({
			pre: [columnIndex, 'asc']
		});
	}

	var settingKey = 'datatables_rowGroup_' + dataTable.settings()[0].sTableId;
	Grocy.FrontendHelpers.SaveUserSetting(settingKey, JSON.stringify(rowGroup));

	dataTable.draw();
});
