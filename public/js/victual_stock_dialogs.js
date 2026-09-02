// The "Undo" helpers the stock booking toasts call.
//
// Loaded on every page from views/layout/default.blade.php, straight after
// victual_entity.js. Plan 12 step 5; no bundler, per that plan's Q6 - a third <script>
// tag is the whole cost of sharing this code.
//
// These three functions exist here rather than in a page script because they are reached
// from inline `onclick=` markup inside a toast, and the toast is not always shown by the
// page that built it: consume, purchase, transfer and inventory post their success message
// to the *parent* window when they are opened in a modal, so the "Undo" link runs against
// whatever the parent page has defined. That is why they must be globals under exactly
// these names, and why they used to be five near-identical copies plus a `@push` of
// purchase.js into three unrelated Blade views - stockoverview, stockentries and
// shoppinglist pushed a page script purely to import symbols from it, which nothing
// declared and no static check could see.
//
// UndoStockTransaction and UndoStockBooking are the versions purchase.js had, complete
// with the "ProductChanged" broadcast: those are the copies stockoverview and stockentries
// actually executed through the push, so keeping them is what makes removing the push a
// no-op there. See plan 12's Executed section for the diff of the five copies and why the
// broadcasting version is the one that survived.

/**
 * Undoes a single stock booking (POST stock/bookings/{id}/undo) and tells every open view
 * of the affected product to refresh itself.
 *
 * The product id is not known to the caller, so it is read back from the booking after the
 * undo - which is why UndoStockBookingEntry below exists as a separate function: the stock
 * entries page already has the product id and does not need the extra round trip.
 * @param {number|string} bookingId The stock booking id to undo
 */
function UndoStockBooking(bookingId)
{
	Victual.Api.Post('stock/bookings/' + bookingId.toString() + '/undo', {},
		function (result)
		{
			toastr.success(__t("Booking successfully undone"));

			Victual.Api.Get('stock/bookings/' + bookingId.toString(),
				function (result)
				{
					Victual.StockDialogs.BroadcastProductChanged(result.product_id);
				}
			);
		}
	);
};

/**
 * Undoes a whole stock transaction (POST stock/transactions/{id}/undo) - a purchase, a
 * transfer or an inventory correction is several bookings sharing one transaction id - and
 * tells every open view of the affected product to refresh itself.
 *
 * Called from the "Undo" link in the success toast of the consume, purchase, transfer,
 * inventory, meal plan and stock overview flows.
 * @param {number|string} transactionId The stock transaction id to undo
 */
function UndoStockTransaction(transactionId)
{
	Victual.Api.Post('stock/transactions/' + transactionId.toString() + '/undo', {},
		function (result)
		{
			toastr.success(__t("Transaction successfully undone"));

			Victual.Api.Get('stock/transactions/' + transactionId.toString(),
				function (result)
				{
					Victual.StockDialogs.BroadcastProductChanged(result[0].product_id);
				}
			);
		}
	);
};

/**
 * Undoes a single stock booking the way the stock entries page needs it: the caller already
 * knows the product id, so the broadcast happens immediately and no booking is re-fetched.
 *
 * Kept distinct from UndoStockBooking deliberately - the signature is the contract of the
 * inline "Undo" links in stockentries.js's consume and open toasts, and the second argument
 * is part of that contract even though the undo itself does not use it.
 * @param {number|string} bookingId The stock booking id to undo
 * @param {number|string} stockRowId The stock row the booking belonged to; unused here, kept
 *                                   for the caller's context
 * @param {number|string} productId The product to broadcast as changed
 */
function UndoStockBookingEntry(bookingId, stockRowId, productId)
{
	Victual.Api.Post('stock/bookings/' + bookingId.toString() + '/undo', {},
		function (result)
		{
			Victual.StockDialogs.BroadcastProductChanged(productId);
			toastr.success(__t("Booking successfully undone"));
		}
	);
};

Victual.StockDialogs = {};

/**
 * Posts a "ProductChanged" broadcast to the topmost window, which is how stockoverview and
 * stockentries learn to redraw one product's row instead of reloading the page.
 * @param {number|string} productId The product that changed
 */
Victual.StockDialogs.BroadcastProductChanged = function (productId)
{
	Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", productId)), Victual.BaseUrl);
};

Victual.StockDialogs.UndoStockBooking = UndoStockBooking;
Victual.StockDialogs.UndoStockTransaction = UndoStockTransaction;
Victual.StockDialogs.UndoStockBookingEntry = UndoStockBookingEntry;
