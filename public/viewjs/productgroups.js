// Powers the product groups list view (views/productgroups.blade.php):
// DataTable of all product groups with search, delete confirmation and a "show disabled"
// toggle. All of it is the shared list factory (public/js/victual_entity.js).
//
// Plan 12 step 4: this list used to reload the whole page on the global "CloseLastModal"
// message - the one list in the tree that did. That message also fires on Escape and on
// cancel, so dismissing the dialog without saving reloaded the page too. Its form now
// posts "Reload" after a successful save, the way every other form/list pair here does,
// and victual.js's own listener handles it.

Victual.EntityList({
	table: '#productgroups-table',
	list: '/productgroups',
	showDisabled: true,
	delete: {
		button: '.product-group-delete-button',
		idAttr: 'data-group-id',
		nameAttr: 'data-group-name',
		endpoint: 'objects/product_groups',
		message: 'Are you sure you want to delete product group "%s"?'
	}
});
