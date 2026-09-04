'use strict';

// The by-barcode endpoints, and undo.
//
// Two things in one scenario because they share a fixture and because both are about the
// same property: a booking and its reversal have to leave the ledger where it started.
// `.devtools/frontend/undo-toasts.js` already proves the *UI* undo links work against this
// fork; nothing proved the API's undo behaves the way upstream's does, and undo is the one
// operation where getting it half right is worse than not having it.
//
// The six by-barcode operations are a separate tag in the spec because they resolve a
// product from a barcode before doing what the product endpoint does. That resolution is
// its own query, and it is exactly the kind of query that reads differently per dialect.

const BARCODE = '4006381333931';
const BBD = '2027-06-30';

module.exports = {
	name: 'barcodes-and-undo',
	tags: ['stock', 'barcodes'],

	async run(api) {
		const location = await api.post('/objects/locations',
			{ name: 'Barcode Location' }, { label: 'barcode: create location' });
		const qu = await api.post('/objects/quantity_units',
			{ name: 'Barcode Unit', name_plural: 'Barcode Units' }, { label: 'barcode: create unit' });

		const locationId = location.body && location.body.created_object_id;
		const quId = qu.body && qu.body.created_object_id;

		const product = await api.post('/objects/products', {
			name: 'Barcode Product',
			location_id: locationId,
			qu_id_purchase: quId,
			qu_id_stock: quId
		}, { label: 'barcode: create product' });

		const productId = product.body && product.body.created_object_id;
		if (!productId) {
			await api.get(`/stock/products/by-barcode/${BARCODE}`, { label: 'barcode: lookup (fixture failed)' });
			return;
		}

		await api.post('/objects/product_barcodes', {
			product_id: productId,
			barcode: BARCODE,
			amount: 1
		}, { label: 'barcode: attach barcode' });

		// The resolution itself, before anything is in stock.
		await api.get(`/stock/products/by-barcode/${BARCODE}`, { label: 'barcode: resolve, empty' });
		await api.get('/objects/product_barcodes_view', { label: 'barcode: barcodes view' });

		// --- the five write paths, by barcode -------------------------------------------
		await api.post(`/stock/products/by-barcode/${BARCODE}/add`, {
			amount: 4, best_before_date: BBD, transaction_type: 'purchase', price: 2.0, location_id: locationId
		}, { label: 'barcode: add 4' });

		await api.get(`/stock/products/by-barcode/${BARCODE}`, { label: 'barcode: resolve after add' });

		await api.post(`/stock/products/by-barcode/${BARCODE}/open`, { amount: 1 },
			{ label: 'barcode: open 1' });

		await api.post(`/stock/products/by-barcode/${BARCODE}/consume`, {
			amount: 1, transaction_type: 'consume'
		}, { label: 'barcode: consume 1' });

		await api.post(`/stock/products/by-barcode/${BARCODE}/inventory`, {
			new_amount: 6, best_before_date: BBD, location_id: locationId
		}, { label: 'barcode: inventory to 6' });

		await api.post(`/stock/products/by-barcode/${BARCODE}/transfer`, {
			amount: 1, location_id_from: locationId, location_id_to: locationId
		}, { label: 'barcode: transfer to the same location' });

		await api.get(`/stock/products/${productId}`, { label: 'barcode: details after by-barcode writes' });

		// An unknown barcode. Upstream answers a specific shape here and clients branch on
		// it, so the shape is part of the contract as much as the success one is.
		await api.get('/stock/products/by-barcode/0000000000000', { label: 'barcode: unknown barcode' });

		// --- undo ------------------------------------------------------------------------
		// Booked fresh so that the undo has a known target rather than whatever the steps
		// above left last.
		const booking = await api.post(`/stock/products/${productId}/add`, {
			amount: 2, best_before_date: BBD, transaction_type: 'purchase', price: 3.0, location_id: locationId
		}, { label: 'undo: purchase 2 to undo' });

		await api.get(`/stock/products/${productId}`, { label: 'undo: details before undo' });

		const row = Array.isArray(booking.body) ? booking.body[0] : booking.body;
		const bookingId = row && row.id;
		const transactionId = row && row.transaction_id;

		if (bookingId) {
			await api.post(`/stock/bookings/${bookingId}/undo`, undefined, { label: 'undo: undo booking' });
			await api.get(`/stock/bookings/${bookingId}`, { label: 'undo: booking after undo' });
			// Undoing twice is the interesting call: the second one has to refuse, and
			// refusing differently from upstream is a client-visible difference.
			await api.post(`/stock/bookings/${bookingId}/undo`, undefined, { label: 'undo: undo the same booking twice' });
		}

		await api.get(`/stock/products/${productId}`, { label: 'undo: details after undo' });

		// A whole transaction, which is what one UI action writes and what plan 13 made
		// atomic.
		const consumed = await api.post(`/stock/products/${productId}/consume`, {
			amount: 1, transaction_type: 'consume'
		}, { label: 'undo: consume 1 to undo by transaction' });

		const consumedRow = Array.isArray(consumed.body) ? consumed.body[0] : consumed.body;
		const consumedTransaction = (consumedRow && consumedRow.transaction_id) || transactionId;

		if (consumedTransaction) {
			await api.post(`/stock/transactions/${consumedTransaction}/undo`, undefined,
				{ label: 'undo: undo transaction' });
			await api.get(`/stock/transactions/${consumedTransaction}`, { label: 'undo: transaction after undo' });
		}

		await api.get('/objects/stock_log?order=id%3Aasc&limit=50', { label: 'undo: ledger after undos' });
		await api.post('/stock/bookings/999999/undo', undefined, { label: 'undo: undo a missing booking' });
	}
};
