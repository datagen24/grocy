// View script for the location create/edit form (views/locationform.blade.php):
// saves a location via the objects/locations API endpoints, incl. userfields.
// Everything here is the shared form factory (public/js/victual_entity.js), which owns
// validation, Enter-to-submit, the save/disable/re-enable cycle, the userfields round trip
// and the embedded-dialog Reload message.

Victual.EntityForm({
	form: 'location-form',
	save: '#save-location-button',
	endpoint: 'objects/locations',
	list: '/locations'
});
