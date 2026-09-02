// View script for the meal plan sections list (views/mealplansections.blade.php):
// DataTables listing of meal plan sections (e.g. breakfast/lunch/dinner) with search and
// delete. All of it is the shared list factory (public/js/victual_entity.js); this list
// sorts by the sort_number column rather than by name, and has no "show disabled" toggle.

Victual.EntityList({
	table: '#mealplansections-table',
	list: '/mealplansections',
	order: [[2, 'asc']],
	delete: {
		button: '.mealplansection-delete-button',
		idAttr: 'data-mealplansection-id',
		nameAttr: 'data-mealplansection-name',
		endpoint: 'objects/meal_plan_sections',
		message: 'Are you sure you want to delete meal plan section "%s"?'
	}
});
