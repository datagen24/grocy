// Powers the quantity unit create/edit form (views/quantityunitform.blade.php):
// saves the unit via the objects/quantity_units API, lists its default conversions and offers plural form testing.

// Form submit: POSTs objects/quantity_units (create) or PUTs objects/quantity_units/{id} (edit, id from Victual.EditObjectId).
// The redirect target depends on Victual.QuantityUnitEditFormRedirectUri ("stay"/"reload"/URL, e.g. set by the plural
// testing button below), the save button's data-location attribute ("continue" = stay on the edit page) and whether
// the form is embedded (then the parent window is reloaded instead)
$('.save-quantityunit-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("quantityunit-form", true))
	{
		return;
	}

	var jsonData = $('#quantityunit-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("quantityunit-form");

	if (Victual.QuantityUnitEditFormRedirectUri !== undefined)
	{
		redirectDestination = Victual.QuantityUnitEditFormRedirectUri;
	}
	else
	{
		redirectDestination = U('/quantityunits');
	}

	if ($(e.currentTarget).attr('data-location') == "continue")
	{
		redirectDestination = "reload";
	}

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/quantity_units', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
					}
					else
					{

						if (redirectDestination == "reload")
						{
							window.location.href = U("/quantityunit/" + result.created_object_id.toString());
						}
						else if (redirectDestination == "stay")
						{
							// Do nothing
						}
						else
						{
							window.location.href = redirectDestination.replace("editobjectid", Victual.EditObjectId);
						}
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("quantityunit-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/quantity_units/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save(function()
				{
					if (GetUriParam("embedded") !== undefined)
					{
						window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
					}
					else
					{

						if (redirectDestination == "reload")
						{
							window.location.reload();
						}
						else if (redirectDestination == "stay")
						{
							// Do nothing
						}
						else
						{
							window.location.href = redirectDestination.replace("editobjectid", Victual.EditObjectId);
						}
					}
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("quantityunit-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live validation while typing; also updates the conversions headline with the entered unit name
// and disables the "add conversion" button while the form is invalid
$('#quantityunit-form input').keyup(function(event)
{
	if ($("#name").val())
	{
		$("#qu-conversion-headline-info").text(__t('1 %s is the same as...', $("#name").val()));
	}
	else
	{
		$("#qu-conversion-headline-info").text("");
	}

	if (!Victual.FrontendHelpers.ValidateForm('quantityunit-form'))
	{
		$("#qu-conversion-add-button").addClass("disabled");
	}
	else
	{
		$("#qu-conversion-add-button").removeClass("disabled");
	}

	Victual.FrontendHelpers.ValidateForm('quantityunit-form');
});

// Enter submits the form (when valid)
$('#quantityunit-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('quantityunit-form'))
		{
			return false;
		}
		else
		{
			$('#save-quantityunit-button').click();
		}
	}
});

// DataTables setup for the unit's default conversions list (column 0 = row menu, not sortable/searchable)
var quConversionsTable = $('#qu-conversions-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#qu-conversions-table tbody').removeClass("d-none");
quConversionsTable.columns.adjust().draw();

// Initial setup: load userfields, sync the headline/validation state, focus the name field
Victual.Components.UserfieldsForm.Load();
$("#name").trigger("keyup");
Victual.FrontendHelpers.ValidateForm('quantityunit-form');
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);

// Delete button per conversion row (expects data-qu-conversion-id from the template);
// confirms via bootbox, then DELETEs objects/quantity_unit_conversions/{id} and reloads
$(document).on('click', '.qu-conversion-delete-button', function(e)
{
	var objectId = $(e.currentTarget).attr('data-qu-conversion-id');

	bootbox.confirm({
		message: __t('Are you sure you want to remove this conversion?'),
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
		callback: function(result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/quantity_unit_conversions/' + objectId, {},
					function(result)
					{
						window.location.reload();
					}
				);
			}
		}
	});
});

// Plural form testing: saves the unit first (redirect suppressed via Victual.QuantityUnitEditFormRedirectUri = "stay"),
// then opens the quantityunitpluraltesting view in an embedded iframe dialog
$("#test-quantityunit-plural-forms-button").on("click", function(e)
{
	e.preventDefault();

	Victual.QuantityUnitEditFormRedirectUri = "stay";
	$("#save-quantityunit-button").click();

	bootbox.alert({
		message: '<iframe class="embed-responsive" src="' + U("/quantityunitpluraltesting?embedded&qu=") + Victual.EditObjectId.toString() + '"></iframe>',
		closeButton: false,
		size: "large",
		callback: function(result)
		{
			Victual.QuantityUnitEditFormRedirectUri = undefined;
			Victual.FrontendHelpers.EndUiBusy("quantityunit-form");
		}
	});
});
