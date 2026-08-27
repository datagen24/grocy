// View script for the API key management page (views/manageapikeys.blade.php):
// lists API keys in a DataTable and supports creating, deleting and showing keys as QR code.

// DataTables setup - first column (row menu) is neither orderable nor searchable
var apiKeysTable = $('#apikeys-table').DataTable({
	'order': [[6, 'desc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#apikeys-table tbody').removeClass("d-none");
apiKeysTable.columns.adjust().draw();

// Debounced free text search over the table
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	apiKeysTable.search(value).draw();
}, Grocy.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	apiKeysTable.search("").draw();
});

// Delete an API key after confirmation (DELETE /api/objects/api_keys/{id});
// the buttons carry data-apikey-id/-key/-description from the Blade template
$(document).on('click', '.apikey-delete-button', function(e)
{
	var button = $(e.currentTarget);
	var objectName = button.attr('data-apikey-key');
	var objectDescription = button.attr('data-apikey-description');
	var objectId = button.attr('data-apikey-id');

	if (objectDescription)
	{
		objectName = objectDescription;
	}

	bootbox.confirm({
		message: __t('Are you sure you want to delete API key "%s"?', objectName),
		closeButton: false,
		className: "text-break",
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
				Grocy.Api.Delete('objects/api_keys/' + objectId, {},
					function(result)
					{
						window.location.href = U('/manageapikeys');
					},
					function(xhr)
					{
						console.error(xhr);
					}
				);
			}
		}
	});
});

// Show the key as QR code - regular keys encode "<api url>|<key>",
// iCal special purpose keys encode the ready-to-use calendar URL
$(".apikey-show-qr-button").on("click", function()
{
	var button = $(this);
	var apiKey = button.data("apikey-key");
	var apiKeyType = button.data("apikey-type");
	var apiKeyDescription = button.data("apikey-description");

	var content = U("/api") + "|" + apiKey;
	if (apiKeyType === "special-purpose-calendar-ical")
	{
		content = U("/api/calendar/ical?secret=" + apiKey);
	}

	bootbox.alert({
		message: "<div class='text-center'><h1>" + __t("API key") + "</h1><h2 class='text-muted'>" + apiKeyDescription + "</h2><p><hr>" + QrCodeImgHtml(content) + "</p></div>",
		closeButton: false
	});
});

// "Add" opens a modal asking for a description; the key itself is then
// generated server side by navigating to /manageapikeys/new
$("#add-api-key-button").on("click", function(e)
{
	$("#add-api-key-modal").modal("show");
});

$("#add-api-key-modal").on("shown.bs.modal", function(e)
{
	setTimeout(function()
	{
		$("#description").focus();
	}, Grocy.FormFocusDelay);
});

$("#new-api-key-button").on("click", function(e)
{
	window.location.href = U("/manageapikeys/new?description=" + encodeURIComponent($("#description").val()));
});
