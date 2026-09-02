// Powers the custom "user entities" list view (userentities.blade.php): user-defined
// data structures managed under Settings. Table listing, search filtering, deletion.
// All of it is the shared list factory (public/js/victual_entity.js).

Victual.EntityList({
	table: '#userentities-table',
	list: '/userentities',
	delete: {
		button: '.userentity-delete-button',
		idAttr: 'data-userentity-id',
		nameAttr: 'data-userentity-name',
		endpoint: 'objects/userentities',
		message: 'Are you sure you want to delete userentity "%s"?'
	}
});
