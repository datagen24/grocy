// Powers the task category create/edit modal form (taskcategoryform.blade.php).
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT, both
// inside the shared form factory (public/js/victual_entity.js).

Victual.EntityForm({
	form: 'task-category-form',
	save: '#save-task-category-button',
	endpoint: 'objects/task_categories',
	list: '/taskcategories'
});
