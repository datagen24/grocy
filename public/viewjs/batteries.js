// View script for the batteries master data list (views/batteries.blade.php):
// DataTable setup, search filter, delete via DELETE /api/objects/batteries/{id}, show/hide
// disabled batteries. All of it is the shared list factory (public/js/victual_entity.js);
// the extra column definition is this list's own - column 4 sorts numerically.

Victual.EntityList({
	table: '#batteries-table',
	list: '/batteries',
	columnDefs: [
		{ 'type': 'num', 'targets': 4 }
	],
	showDisabled: true,
	resetShowDisabled: true,
	delete: {
		button: '.battery-delete-button',
		idAttr: 'data-battery-id',
		nameAttr: 'data-battery-name',
		endpoint: 'objects/batteries',
		message: 'Are you sure you want to delete battery "%s"?'
	}
});
