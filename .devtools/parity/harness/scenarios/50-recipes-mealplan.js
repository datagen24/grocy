'use strict';

// Recipes, their positions, fulfilment, and the meal plan.
//
// `recipes_resolved` is the deepest view stack in the schema — a view over views over the
// stock views — and its `costs` and `costs_per_serving` columns are named in ADR-0005's
// float-accumulation exception. Fulfilment is the query that answers "can I cook this",
// which reads current stock through that stack, so it is the single best test of whether
// the view port preserved arithmetic.
//
// Nested recipes get their own recipe here rather than being skipped: `recipes_nestings`
// is a self-referencing table, and a recursive read is the thing an engine port is most
// likely to have spelled differently.

module.exports = {
	name: 'recipes-mealplan',
	tags: ['recipes'],

	async run(api) {
		const location = await api.post('/objects/locations', { name: 'Recipe Location' },
			{ label: 'recipes: create location' });
		const qu = await api.post('/objects/quantity_units',
			{ name: 'Recipe Unit', name_plural: 'Recipe Units' }, { label: 'recipes: create unit' });

		const locationId = location.body && location.body.created_object_id;
		const quId = qu.body && qu.body.created_object_id;

		// Two ingredients: one in stock, one not, so fulfilment has both answers to give.
		const stocked = await api.post('/objects/products', {
			name: 'Recipe Ingredient In Stock',
			location_id: locationId, qu_id_purchase: quId, qu_id_stock: quId
		}, { label: 'recipes: create stocked ingredient' });

		const missing = await api.post('/objects/products', {
			name: 'Recipe Ingredient Missing',
			location_id: locationId, qu_id_purchase: quId, qu_id_stock: quId
		}, { label: 'recipes: create missing ingredient' });

		const stockedId = stocked.body && stocked.body.created_object_id;
		const missingId = missing.body && missing.body.created_object_id;

		if (stockedId) {
			await api.post(`/stock/products/${stockedId}/add`, {
				amount: 10, best_before_date: '2030-01-01', transaction_type: 'purchase',
				price: 1.5, location_id: locationId
			}, { label: 'recipes: stock the stocked ingredient' });
		}

		const recipe = await api.post('/objects/recipes', {
			name: 'Parity Recipe',
			description: 'parity fixture',
			base_servings: 2,
			desired_servings: 2
		}, { label: 'recipes: create recipe' });

		const recipeId = recipe.body && recipe.body.created_object_id;
		if (!recipeId) {
			await api.get('/recipes/fulfillment', { label: 'recipes: fulfillment (fixture failed)' });
			return;
		}

		if (stockedId) {
			await api.post('/objects/recipes_pos', {
				recipe_id: recipeId, product_id: stockedId, amount: 2, qu_id: quId
			}, { label: 'recipes: add stocked position' });
		}
		if (missingId) {
			await api.post('/objects/recipes_pos', {
				recipe_id: recipeId, product_id: missingId, amount: 5, qu_id: quId
			}, { label: 'recipes: add missing position' });
		}

		await api.get(`/objects/recipes/${recipeId}`, { label: 'recipes: read recipe' });
		await api.get('/objects/recipes_pos?order=id%3Aasc', { label: 'recipes: positions' });
		await api.get('/objects/recipes_pos_resolved?order=id%3Aasc', { label: 'recipes: positions resolved view' });

		// The two fulfilment endpoints: all recipes, and one recipe.
		await api.get('/recipes/fulfillment', { label: 'recipes: fulfillment, all' });
		await api.get(`/recipes/${recipeId}/fulfillment`, { label: 'recipes: fulfillment, one' });

		// Adding what is missing to the shopping list is the fulfilment query used for a
		// write, so it can disagree with the read in a way neither alone would show.
		await api.post(`/recipes/${recipeId}/add-not-fulfilled-products-to-shoppinglist`, {},
			{ label: 'recipes: add not fulfilled to shopping list' });
		await api.get('/objects/shopping_list?order=id%3Aasc', { label: 'recipes: shopping list after' });

		// Copy, then nest the copy, then read the nesting back.
		const copied = await api.post(`/recipes/${recipeId}/copy`, undefined, { label: 'recipes: copy' });
		const copiedId = copied.body && (copied.body.created_object_id || copied.body.id);

		if (copiedId) {
			await api.post('/objects/recipes_nestings', {
				recipe_id: recipeId, includes_recipe_id: copiedId, servings: 1
			}, { label: 'recipes: nest the copy' });
			await api.get('/objects/recipes_nestings?order=id%3Aasc', { label: 'recipes: nestings' });
			await api.get(`/recipes/${recipeId}/fulfillment`, { label: 'recipes: fulfillment with a nesting' });
		}

		// Consuming a recipe books every position at once — one transaction, several
		// bookings, which is plan 13's atomicity under a different name.
		await api.post(`/recipes/${recipeId}/consume`, {}, { label: 'recipes: consume' });
		await api.get('/objects/stock_log?order=id%3Aasc&limit=50', { label: 'recipes: ledger after consume' });

		// --- meal plan --------------------------------------------------------------------
		const section = await api.post('/objects/meal_plan_sections',
			{ name: 'Parity Meal Section', sort_number: 1 }, { label: 'mealplan: create section' });
		const sectionId = section.body && section.body.created_object_id;

		await api.post('/objects/meal_plan', {
			day: '2026-05-01',
			type: 'recipe',
			recipe_id: recipeId,
			recipe_servings: 2,
			section_id: sectionId
		}, { label: 'mealplan: add recipe entry' });

		await api.post('/objects/meal_plan', {
			day: '2026-05-02',
			type: 'note',
			note: 'Parity note entry',
			section_id: sectionId
		}, { label: 'mealplan: add note entry' });

		await api.get('/objects/meal_plan?order=id%3Aasc', { label: 'mealplan: list' });

		await api.get('/recipes/999999/fulfillment', { label: 'recipes: fulfillment for a missing recipe' });
	}
};
