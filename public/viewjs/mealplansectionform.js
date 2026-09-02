// View script for the meal plan section create/edit form (views/mealplansectionform.blade.php):
// saves a meal plan section via the objects/meal_plan_sections API endpoints, through the
// shared form factory (public/js/victual_entity.js). A meal plan section carries no
// userfields, so the factory's userfields round trip is switched off.
//
// This form's Enter-to-submit used to click `#save-mealplansections-button` - plural,
// and no such element exists - so Enter did nothing. The factory calls the save function
// directly rather than a button selector, so it cannot drift that way again.

Victual.EntityForm({
	form: 'mealplansection-form',
	save: '#save-mealplansection-button',
	endpoint: 'objects/meal_plan_sections',
	list: '/mealplansections',
	userfields: false,
	comboboxGuard: false
});
