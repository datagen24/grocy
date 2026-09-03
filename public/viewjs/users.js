// Powers the users list view (users.blade.php): table listing, search filtering, and
// user deletion (via the dedicated "users" endpoint, not the generic objects/* CRUD API).
// All of it is the shared list factory (public/js/victual_entity.js).

Victual.EntityList({
	table: '#users-table',
	list: '/users',
	delete: {
		button: '.user-delete-button',
		idAttr: 'data-user-id',
		nameAttr: 'data-user-username',
		endpoint: 'users',
		message: 'Are you sure you want to delete user "%s"?'
	}
});
