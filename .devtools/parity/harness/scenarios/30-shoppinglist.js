'use strict';

// Shopping lists, including the six endpoints that *derive* a list from stock.
//
// The derived ones — missing, overdue, expired — are queries over the stock views rather
// than over a table, so they are the shopping-list feature's engine-sensitive half. They
// also depend on the fixture built by the stock scenario having run first, which is why
// this scenario builds its own understocked product rather than assuming one.

const PAST = '2020-01-01';
const FUTURE = '2030-01-01';

module.exports = {
	name: 'shoppinglist',
	tags: ['shopping list', 'stock'],

	async run(api) {
		const location = await api.post('/objects/locations',
			{ name: 'Shopping Location' }, { label: 'shoppinglist: create location' });
		const qu = await api.post('/objects/quantity_units',
			{ name: 'Shopping Unit', name_plural: 'Shopping Units' }, { label: 'shoppinglist: create unit' });

		const locationId = location.body && location.body.created_object_id;
		const quId = qu.body && qu.body.created_object_id;

		// Understocked on purpose: min_stock_amount above what gets booked, so
		// add-missing-products has something to find.
		const understocked = await api.post('/objects/products', {
			name: 'Understocked Product',
			location_id: locationId,
			qu_id_purchase: quId,
			qu_id_stock: quId,
			min_stock_amount: 10
		}, { label: 'shoppinglist: create understocked product' });

		// Already expired on purpose, for add-expired-products.
		const expiring = await api.post('/objects/products', {
			name: 'Expired Product',
			location_id: locationId,
			qu_id_purchase: quId,
			qu_id_stock: quId,
			min_stock_amount: 0
		}, { label: 'shoppinglist: create expiring product' });

		const understockedId = understocked.body && understocked.body.created_object_id;
		const expiringId = expiring.body && expiring.body.created_object_id;

		if (understockedId) {
			await api.post(`/stock/products/${understockedId}/add`, {
				amount: 2, best_before_date: FUTURE, transaction_type: 'purchase', price: 1.0, location_id: locationId
			}, { label: 'shoppinglist: stock 2 of 10 needed' });
		}
		if (expiringId) {
			await api.post(`/stock/products/${expiringId}/add`, {
				amount: 1, best_before_date: PAST, transaction_type: 'purchase', price: 1.0, location_id: locationId
			}, { label: 'shoppinglist: stock 1 already expired' });
		}

		await api.get('/objects/shopping_list', { label: 'shoppinglist: empty' });

		// --- explicit membership ----------------------------------------------------------
		if (understockedId) {
			await api.post('/stock/shoppinglist/add-product', {
				product_id: understockedId, product_amount: 3, note: 'parity note'
			}, { label: 'shoppinglist: add product' });

			await api.get('/objects/shopping_list', { label: 'shoppinglist: after add' });
			await api.get('/objects/uihelper_shopping_list', { label: 'shoppinglist: uihelper view' });

			// Adding the same product twice should accumulate rather than duplicate, and
			// which of those it does is a client-visible behaviour.
			await api.post('/stock/shoppinglist/add-product', {
				product_id: understockedId, product_amount: 2
			}, { label: 'shoppinglist: add the same product again' });

			await api.get('/objects/shopping_list', { label: 'shoppinglist: after second add' });

			await api.post('/stock/shoppinglist/remove-product', {
				product_id: understockedId, product_amount: 1
			}, { label: 'shoppinglist: remove one' });

			await api.get('/objects/shopping_list', { label: 'shoppinglist: after remove' });
		}

		// A free-text row, which is the shopping list's other kind of member and goes
		// through a different validation path than a product row.
		const freeText = await api.post('/objects/shopping_list', {
			note: 'Parity free text row', amount: 1
		}, { label: 'shoppinglist: create free-text row' });

		const freeTextId = freeText.body && freeText.body.created_object_id;
		if (freeTextId) {
			await api.put(`/objects/shopping_list/${freeTextId}`, { done: 1 },
				{ label: 'shoppinglist: mark done' });
			await api.get(`/objects/shopping_list/${freeTextId}`, { label: 'shoppinglist: read done row' });
		}

		// --- derived membership ------------------------------------------------------------
		await api.post('/stock/shoppinglist/clear', {}, { label: 'shoppinglist: clear' });
		await api.get('/objects/shopping_list', { label: 'shoppinglist: after clear' });

		await api.post('/stock/shoppinglist/add-missing-products', {},
			{ label: 'shoppinglist: add missing products' });
		await api.get('/objects/shopping_list?order=id%3Aasc', { label: 'shoppinglist: after add missing' });

		await api.post('/stock/shoppinglist/add-overdue-products', {},
			{ label: 'shoppinglist: add overdue products' });
		await api.get('/objects/shopping_list?order=id%3Aasc', { label: 'shoppinglist: after add overdue' });

		await api.post('/stock/shoppinglist/add-expired-products', {},
			{ label: 'shoppinglist: add expired products' });
		await api.get('/objects/shopping_list?order=id%3Aasc', { label: 'shoppinglist: after add expired' });

		// `clear` with done_only is the variant the UI's "clear done" button uses, and it
		// takes a body where the plain clear does not.
		await api.post('/stock/shoppinglist/clear', { list_id: 1, done_only: true },
			{ label: 'shoppinglist: clear done only' });
		await api.get('/objects/shopping_list?order=id%3Aasc', { label: 'shoppinglist: after clear done only' });

		await api.post('/stock/shoppinglist/add-product', { product_id: 999999, product_amount: 1 },
			{ label: 'shoppinglist: add a missing product id' });
	}
};
