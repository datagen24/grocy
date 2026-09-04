'use strict';

// The stock ledger, which is the application.
//
// Twenty-eight of the eighty-six documented operations are on this tag, and every one of
// them writes through StockService — the code plan 13 made transactional and the code
// ADR-0009 wants to move into views. It is also where the fork's engine change has the
// most room to be wrong: the booking paths run triggers, the overview reads views built on
// views, and `products_average_price` is one of the two places ADR-0005 accepts a
// difference. So this scenario builds a real product, moves it through every transaction
// type in a fixed order, and reads the ledger back after each one.
//
// Fixed dates throughout. `best_before_date` computed from today would differ between two
// instances only if they ran across a midnight, which is the kind of flake that gets a
// suite ignored.

const BBD = '2027-06-30';
const BBD_LATER = '2027-12-31';

// Shared with the scenarios that come after this one; they need a product in stock and
// building a second one would compare a different thing.
const FIXTURE = {
	locationA: null,
	locationB: null,
	quStock: null,
	product: null
};

async function setUp(api) {
	const locationA = await api.post('/objects/locations',
		{ name: 'Stock Location A', description: 'parity fixture' },
		{ label: 'stock: create location A' });
	const locationB = await api.post('/objects/locations',
		{ name: 'Stock Location B', description: 'parity fixture' },
		{ label: 'stock: create location B' });
	const qu = await api.post('/objects/quantity_units',
		{ name: 'Stock Piece', name_plural: 'Stock Pieces' },
		{ label: 'stock: create quantity unit' });

	FIXTURE.locationA = locationA.body && locationA.body.created_object_id;
	FIXTURE.locationB = locationB.body && locationB.body.created_object_id;
	FIXTURE.quStock = qu.body && qu.body.created_object_id;

	const product = await api.post('/objects/products', {
		name: 'Parity Product',
		description: 'created by the parity suite',
		location_id: FIXTURE.locationA,
		qu_id_purchase: FIXTURE.quStock,
		qu_id_stock: FIXTURE.quStock,
		min_stock_amount: 2,
		default_best_before_days: 30,
		enable_tare_weight_handling: 0
	}, { label: 'stock: create product' });

	FIXTURE.product = product.body && product.body.created_object_id;
	return FIXTURE.product !== undefined && FIXTURE.product !== null;
}

module.exports = {
	name: 'stock',
	tags: ['stock'],
	fixture: FIXTURE,

	async run(api) {
		if (!(await setUp(api))) {
			// The product is the subject of every step below. Without it they would all be
			// 404s on both sides and the scenario would report parity while checking
			// nothing — the failure mode a green suite must not be able to have.
			await api.get('/stock', { label: 'stock: overview (fixture failed)' });
			return;
		}

		const p = FIXTURE.product;

		await api.get(`/stock/products/${p}`, { label: 'stock: product details, empty' });
		await api.get('/stock', { label: 'stock: overview, empty' });
		await api.get('/stock/volatile', { label: 'stock: volatile, empty' });

		// --- purchase ---------------------------------------------------------------
		// Two purchases at different prices, because one price makes the average price
		// view trivially correct. Two is what makes it an average, and the average price
		// is one of ADR-0005's two accepted differences.
		const purchase1 = await api.post(`/stock/products/${p}/add`, {
			amount: 5,
			best_before_date: BBD,
			transaction_type: 'purchase',
			price: 1.23,
			location_id: FIXTURE.locationA
		}, { label: 'stock: purchase 5 @ 1.23' });

		await api.post(`/stock/products/${p}/add`, {
			amount: 3,
			best_before_date: BBD_LATER,
			transaction_type: 'purchase',
			price: 4.56,
			location_id: FIXTURE.locationB
		}, { label: 'stock: purchase 3 @ 4.56' });

		await api.get(`/stock/products/${p}`, { label: 'stock: product details after purchase' });
		await api.get(`/stock/products/${p}/entries`, { label: 'stock: entries' });
		await api.get(`/stock/products/${p}/locations`, { label: 'stock: product locations' });
		await api.get(`/stock/products/${p}/price-history`, { label: 'stock: price history' });
		await api.get('/stock', { label: 'stock: overview after purchase' });
		await api.get(`/stock/locations/${FIXTURE.locationA}/entries`, { label: 'stock: location A entries' });

		// The average price view, read directly. It is the ADR-0005 exception, so this
		// step is expected to be either identical or accepted — never merely absent.
		await api.get('/objects/products_average_price', { label: 'stock: average price view' });
		await api.get('/objects/products_last_purchased', { label: 'stock: last purchased view' });

		// --- consume ------------------------------------------------------------------
		await api.post(`/stock/products/${p}/consume`, {
			amount: 2,
			transaction_type: 'consume',
			spoiled: false,
			location_id: FIXTURE.locationA
		}, { label: 'stock: consume 2' });

		await api.post(`/stock/products/${p}/consume`, {
			amount: 1,
			transaction_type: 'consume',
			spoiled: true,
			location_id: FIXTURE.locationA
		}, { label: 'stock: consume 1 spoiled' });

		await api.get(`/stock/products/${p}`, { label: 'stock: product details after consume' });

		// --- open ---------------------------------------------------------------------
		await api.post(`/stock/products/${p}/open`, { amount: 1 }, { label: 'stock: open 1' });
		await api.get(`/stock/products/${p}/entries`, { label: 'stock: entries after open' });

		// --- transfer -----------------------------------------------------------------
		await api.post(`/stock/products/${p}/transfer`, {
			amount: 1,
			location_id_from: FIXTURE.locationA,
			location_id_to: FIXTURE.locationB
		}, { label: 'stock: transfer 1 A→B' });

		await api.get(`/stock/products/${p}/locations`, { label: 'stock: locations after transfer' });
		await api.get('/objects/stock_current_locations', { label: 'stock: current locations view' });

		// --- inventory ----------------------------------------------------------------
		// Inventory is the path that has to *derive* a booking from a target amount, which
		// makes it the one most likely to disagree between engines about arithmetic.
		await api.post(`/stock/products/${p}/inventory`, {
			new_amount: 10,
			best_before_date: BBD,
			location_id: FIXTURE.locationA,
			price: 2.5
		}, { label: 'stock: inventory up to 10' });

		await api.post(`/stock/products/${p}/inventory`, {
			new_amount: 4,
			best_before_date: BBD,
			location_id: FIXTURE.locationA
		}, { label: 'stock: inventory down to 4' });

		await api.get(`/stock/products/${p}`, { label: 'stock: product details after inventory' });

		// --- the ledger itself ----------------------------------------------------------
		await api.get('/objects/stock_log?limit=50&order=id%3Aasc', { label: 'stock: log' });
		await api.get('/objects/stock?order=id%3Aasc', { label: 'stock: stock view' });
		await api.get('/stock/volatile', { label: 'stock: volatile after bookings' });

		// A single booking and its transaction, read back by id. `bookings/{id}` is the
		// row; `transactions/{id}` is every row a single UI action wrote, which is the
		// thing plan 13 made atomic.
		const bookingId = purchase1.body && (purchase1.body.id || (purchase1.body[0] && purchase1.body[0].id));
		const transactionId = purchase1.body &&
			(purchase1.body.transaction_id || (purchase1.body[0] && purchase1.body[0].transaction_id));

		if (bookingId) {
			await api.get(`/stock/bookings/${bookingId}`, { label: 'stock: read booking' });
		}
		if (transactionId) {
			await api.get(`/stock/transactions/${transactionId}`, { label: 'stock: read transaction' });
		}

		// --- failure paths ---------------------------------------------------------------
		// Consuming more than exists, and a negative amount. Both are refusals rather than
		// crashes on a correct implementation, and plan 11 is about the fork's refusals
		// looking the same as upstream's.
		await api.post(`/stock/products/${p}/consume`, {
			amount: 9999, transaction_type: 'consume'
		}, { label: 'stock: consume more than in stock' });

		await api.post(`/stock/products/${p}/add`, {
			amount: -1, transaction_type: 'purchase'
		}, { label: 'stock: purchase a negative amount' });

		await api.get('/stock/products/999999', { label: 'stock: details for a missing product' });
	}
};
