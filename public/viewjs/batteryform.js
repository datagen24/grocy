// View script for the battery create/edit form (views/batteryform.blade.php):
// saves via POST /api/objects/batteries (create) or PUT /api/objects/batteries/{id}
// (edit, using Victual.EditObjectId) including userfields - all of it the shared form
// factory (public/js/victual_entity.js) - plus grocycode label printing, which is this
// page's own.

Victual.EntityForm({
	form: 'battery-form',
	save: '#save-battery-button',
	endpoint: 'objects/batteries',
	list: '/batteries'
});

// Print a battery grocycode label: GET /api/batteries/{id}/printlabel, then pass the label data to the configured label printer webhook
$(document).on('click', '.battery-grocycode-label-print', function (e)
{
	e.preventDefault();

	var batteryId = $(e.currentTarget).attr('data-battery-id');
	Victual.Api.Get('batteries/' + batteryId + '/printlabel', function (labelData)
	{
		if (Victual.Webhooks.labelprinter !== undefined)
		{
			Victual.FrontendHelpers.RunWebhook(Victual.Webhooks.labelprinter, labelData);
		}
	});
});
