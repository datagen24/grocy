// Powers the shopping location create/edit modal form (shoppinglocationform.blade.php).
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT, both
// inside the shared form factory (public/js/victual_entity.js).

Victual.EntityForm({
	form: 'shoppinglocation-form',
	save: '#save-shopping-location-button',
	endpoint: 'objects/shopping_locations',
	list: '/shoppinglocations'
});
