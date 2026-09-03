// Powers the task categories list view (taskcategories.blade.php): table listing,
// search filtering, delete confirmation, and the disabled-items toggle.
// All of it is the shared list factory (public/js/victual_entity.js).

Victual.EntityList({
	table: '#taskcategories-table',
	list: '/taskcategories',
	showDisabled: true,
	delete: {
		button: '.task-category-delete-button',
		idAttr: 'data-category-id',
		nameAttr: 'data-category-name',
		endpoint: 'objects/task_categories',
		message: 'Are you sure you want to delete task category "%s"?'
	}
});
