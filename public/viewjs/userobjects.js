// Powers the userobjects list view (userobjects.blade.php): lists the records ("objects")
// belonging to one custom user entity. Table listing, search filtering, deletion.
// All of it is the shared list factory (public/js/victual_entity.js). Three things are
// this page's own: the table is selected by class rather than by id, a userobject has no
// name to put in the confirmation, and deleting reloads in place rather than navigating,
// because the list URL carries the owning entity's name.

Victual.EntityList({
	table: '.userobjects-table',
	delete: {
		button: '.userobject-delete-button',
		idAttr: 'data-userobject-id',
		endpoint: 'objects/userobjects',
		message: 'Are you sure you want to delete this userobject?',
		after: function ()
		{
			window.location.reload();
		}
	}
});
