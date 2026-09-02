// Powers the task create/edit modal form (taskform.blade.php). Victual.EditMode
// ('create'/'edit') and Victual.EditObjectId select POST vs PUT, both inside the shared
// form factory (public/js/victual_entity.js). Two submit buttons share the handler: the
// regular save, and an "add another" variant (identified by the "add-another" class) that
// re-opens a fresh create form instead of closing - which is why this form overrides what
// happens after a successful save.

Victual.EntityForm({
	form: 'task-form',
	save: '.save-task-button',
	endpoint: 'objects/tasks',
	list: '/tasks',
	// The user picker's field is named user_id, the API expects assigned_to_user_id, and
	// the due date lives in the date picker component rather than in a plain input.
	body: function (jsonData)
	{
		jsonData.assigned_to_user_id = jsonData.user_id;
		delete jsonData.user_id;
		jsonData.due_date = Victual.Components.DateTimePicker.GetValue();

		return jsonData;
	},
	afterSave: function (context)
	{
		var addAnother = Victual.EditMode === 'create' && context.button && context.button.hasClass("add-another");
		var embedded = GetUriParam("embedded") !== undefined;

		if (addAnother)
		{
			window.location.href = U(embedded ? '/task/new?embedded' : '/task/new');
		}
		else if (embedded)
		{
			window.parent.postMessage(WindowMessageBag("Reload"), Victual.BaseUrl);
		}
		else
		{
			window.location.href = U('/tasks');
		}
	}
});

// The due date input needs one validation pass of its own once the picker is wired up
Victual.Components.DateTimePicker.GetInputElement().trigger('input');
Victual.FrontendHelpers.ValidateForm('task-form');
