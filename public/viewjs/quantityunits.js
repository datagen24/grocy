// Powers the quantity units list view (views/quantityunits.blade.php):
// DataTable of all quantity units with search, delete confirmation and a "show disabled"
// toggle. All of it is the shared list factory (public/js/victual_entity.js), which also
// puts this dialog's Yes/No button labels through __t() - this was the one copy in the
// tree that had lost that call.

Victual.EntityList({
	table: '#quantityunits-table',
	list: '/quantityunits',
	showDisabled: true,
	delete: {
		button: '.quantityunit-delete-button',
		idAttr: 'data-quantityunit-id',
		nameAttr: 'data-quantityunit-name',
		endpoint: 'objects/quantity_units',
		message: 'Are you sure you want to delete quantity unit "%s"?'
	}
});
