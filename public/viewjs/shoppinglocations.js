// Powers the shopping locations (stores) list view (shoppinglocations.blade.php):
// table listing, search filtering, delete confirmation, and the disabled-items toggle.
// All of it is the shared list factory (public/js/victual_entity.js).

Victual.EntityList({
	table: '#shoppinglocations-table',
	list: '/shoppinglocations',
	showDisabled: true,
	delete: {
		button: '.shoppinglocation-delete-button',
		idAttr: 'data-shoppinglocation-id',
		nameAttr: 'data-shoppinglocation-name',
		endpoint: 'objects/shopping_locations',
		message: 'Are you sure you want to delete store "%s"?'
	}
});
