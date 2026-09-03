// The page inventory the baseline harness walks.
//
// Every entry is a fact about the tree as it is today, read out of the Blade view and the
// view script: table selector, delete button class, the data attributes carrying id and
// name, the save button. Nothing here is discovered at run time on purpose - when plan 12
// step 3 replaces these scripts with a factory, a selector that quietly changed is exactly
// what this baseline exists to catch.
//
// Selectors are written in full (leading # or .) because the tree is not uniform: most
// lists have an id'd table and an id'd save button, `tasks` has a class'd save button and
// `userobjects` a class'd table.

// Master data lists that get a full create/edit/delete round trip.
// `add` is the href of the list's "Add" button; the ones ending in ?embedded open the form
// in an iframe dialog, which is where the embedded-reload convention lives.
const LISTS = [
	{
		key: 'locations',
		list: '/locations',
		table: '#locations-table',
		add: '/location/new?embedded',
		edit: id => '/location/' + id,
		form: '#location-form',
		save: '#save-location-button',
		deleteButton: '.location-delete-button',
		idAttr: 'data-location-id',
		nameAttr: 'data-location-name'
	},
	{
		key: 'shoppinglocations',
		list: '/shoppinglocations',
		table: '#shoppinglocations-table',
		add: '/shoppinglocation/new?embedded',
		edit: id => '/shoppinglocation/' + id,
		form: '#shoppinglocation-form',
		save: '#save-shopping-location-button',
		deleteButton: '.shoppinglocation-delete-button',
		idAttr: 'data-shoppinglocation-id',
		nameAttr: 'data-shoppinglocation-name'
	},
	{
		key: 'quantityunits',
		list: '/quantityunits',
		table: '#quantityunits-table',
		add: '/quantityunit/new',
		edit: id => '/quantityunit/' + id,
		form: '#quantityunit-form',
		// Two save buttons sharing a class, not an id.
		save: '.save-quantityunit-button',
		deleteButton: '.quantityunit-delete-button',
		idAttr: 'data-quantityunit-id',
		nameAttr: 'data-quantityunit-name'
	},
	{
		key: 'productgroups',
		list: '/productgroups',
		table: '#productgroups-table',
		add: '/productgroup/new?embedded',
		edit: id => '/productgroup/' + id,
		form: '#product-group-form',
		save: '#save-product-group-button',
		deleteButton: '.product-group-delete-button',
		idAttr: 'data-group-id',
		nameAttr: 'data-group-name'
	},
	{
		key: 'batteries',
		list: '/batteries',
		table: '#batteries-table',
		add: '/battery/new?embedded',
		edit: id => '/battery/' + id,
		form: '#battery-form',
		save: '#save-battery-button',
		deleteButton: '.battery-delete-button',
		idAttr: 'data-battery-id',
		nameAttr: 'data-battery-name'
	},
	{
		key: 'chores',
		list: '/chores',
		table: '#chores-table',
		add: '/chore/new',
		edit: id => '/chore/' + id,
		form: '#chore-form',
		save: '#save-chore-button',
		deleteButton: '.chore-delete-button',
		idAttr: 'data-chore-id',
		nameAttr: 'data-chore-name'
	},
	{
		key: 'equipment',
		list: '/equipment',
		table: '#equipment-table',
		add: '/equipment/new',
		edit: id => '/equipment/' + id,
		form: '#equipment-form',
		save: '#save-equipment-button',
		deleteButton: '.equipment-delete-button',
		idAttr: 'data-equipment-id',
		nameAttr: 'data-equipment-name'
	},
	{
		key: 'taskcategories',
		list: '/taskcategories',
		table: '#taskcategories-table',
		add: '/taskcategory/new?embedded',
		edit: id => '/taskcategory/' + id,
		form: '#task-category-form',
		save: '#save-task-category-button',
		deleteButton: '.task-category-delete-button',
		idAttr: 'data-category-id',
		nameAttr: 'data-category-name'
	},
	{
		key: 'mealplansections',
		list: '/mealplansections',
		table: '#mealplansections-table',
		add: '/mealplansection/new?embedded',
		edit: id => '/mealplansection/' + id,
		form: '#mealplansection-form',
		save: '#save-mealplansection-button',
		deleteButton: '.mealplansection-delete-button',
		idAttr: 'data-mealplansection-id',
		nameAttr: 'data-mealplansection-name'
	},
	{
		key: 'tasks',
		list: '/tasks',
		table: '#tasks-table',
		add: '/task/new?embedded',
		edit: id => '/task/' + id,
		form: '#task-form',
		save: '.save-task-button',
		deleteButton: '.delete-task-button',
		idAttr: 'data-task-id',
		nameAttr: 'data-task-name'
	},
	{
		key: 'userfields',
		list: '/userfields',
		table: '#userfields-table',
		add: '/userfield/new?embedded',
		edit: id => '/userfield/' + id,
		form: '#userfield-form',
		save: '#save-userfield-button',
		deleteButton: '.userfield-delete-button',
		idAttr: 'data-userfield-id',
		nameAttr: 'data-userfield-name',
		// name is pattern="^[a-zA-Z0-9_]*$" on this form.
		slugName: true
	},
	{
		key: 'userentities',
		list: '/userentities',
		table: '#userentities-table',
		add: '/userentity/new?embedded',
		edit: id => '/userentity/' + id,
		form: '#userentity-form',
		save: '#save-userentity-button',
		deleteButton: '.userentity-delete-button',
		idAttr: 'data-userentity-id',
		nameAttr: 'data-userentity-name',
		// name is pattern="^[a-zA-Z0-9_]*$", and is disabled once the entity exists, so the
		// edit step has to rename the caption instead.
		slugName: true,
		nameImmutableOnEdit: true,
		editField: '#caption'
	},
	{
		key: 'users',
		list: '/users',
		table: '#users-table',
		add: '/user/new',
		edit: id => '/user/' + id,
		form: '#user-form',
		save: '#save-user-button',
		deleteButton: '.user-delete-button',
		idAttr: 'data-user-id',
		nameAttr: 'data-user-username',
		// The user form has no #name and needs a matching password pair, so the generic
		// required-field filler is handed an explicit map.
		nameField: '#username',
		extraFields: { '#password': 'VictualBaseline1!', '#password_confirm': 'VictualBaseline1!' }
	}
];

// Pages that exist but are not round-tripped: they need another object to exist first, or
// they are the partial-clone / leave-alone scripts of the plan's Q5. Loading them still
// records console errors and a row count, which is what step 3 must not change either.
const LOAD_ONLY_LISTS = [
	{ key: 'products', list: '/products', table: '#products-table' },
	{ key: 'recipes', list: '/recipes', table: '#recipes-table' },
	{ key: 'shoppinglist', list: '/shoppinglist', table: '#shoppinglist-table' },
	{ key: 'stockoverview', list: '/stockoverview', table: '#stock-overview-table' },
	{ key: 'stockentries', list: '/stockentries', table: '#stockentries-table' },
	{ key: 'stockjournal', list: '/stockjournal', table: '#stock-journal-table' },
	{ key: 'manageapikeys', list: '/manageapikeys', table: '#apikeys-table' },
	{ key: 'quantityunitconversionsresolved', list: '/quantityunitconversionsresolved', table: '#qu-conversions-resolved-table' },
	{ key: 'mealplan', list: '/mealplan', table: null },
	{ key: 'userobjects', list: '/userobjects/{userentity}', table: '.userobjects-table', needs: 'userentity' }
];

// The 22 *form.js scripts, by the URL that puts them in create mode. The ones a list's Add
// button reaches are already round-tripped above; this pass records that every form page
// renders, has its form and save button where the script expects them, and says nothing on
// the console.
const FORMS = [
	{ key: 'locationform', url: '/location/new', form: '#location-form', save: '#save-location-button' },
	{ key: 'shoppinglocationform', url: '/shoppinglocation/new', form: '#shoppinglocation-form', save: '#save-shopping-location-button' },
	{ key: 'quantityunitform', url: '/quantityunit/new', form: '#quantityunit-form', save: '.save-quantityunit-button' },
	{ key: 'productgroupform', url: '/productgroup/new', form: '#product-group-form', save: '#save-product-group-button' },
	{ key: 'batteryform', url: '/battery/new', form: '#battery-form', save: '#save-battery-button' },
	{ key: 'choreform', url: '/chore/new', form: '#chore-form', save: '#save-chore-button' },
	{ key: 'equipmentform', url: '/equipment/new', form: '#equipment-form', save: '#save-equipment-button' },
	{ key: 'taskcategoryform', url: '/taskcategory/new', form: '#task-category-form', save: '#save-task-category-button' },
	{ key: 'mealplansectionform', url: '/mealplansection/new', form: '#mealplansection-form', save: '#save-mealplansection-button' },
	{ key: 'taskform', url: '/task/new', form: '#task-form', save: '.save-task-button' },
	{ key: 'userfieldform', url: '/userfield/new', form: '#userfield-form', save: '#save-userfield-button' },
	{ key: 'userentityform', url: '/userentity/new', form: '#userentity-form', save: '#save-userentity-button' },
	{ key: 'userform', url: '/user/new', form: '#user-form', save: '#save-user-button' },
	{ key: 'productform', url: '/product/new', form: '#product-form', save: '#save-product-button' },
	{ key: 'recipeform', url: '/recipe/new', form: '#recipe-form', save: '.save-recipe' },
	{ key: 'shoppinglistform', url: '/shoppinglist/new?embedded', form: '#shopping-list-form', save: '#save-shopping-list-button' },
	{ key: 'shoppinglistitemform', url: '/shoppinglistitem/new?embedded&list=1', form: '#shoppinglist-form', save: '#save-shoppinglist-button' },
	{ key: 'stockentryform', url: '/stockentry/1', form: '#stockentry-form', save: '#save-stockentry-button' },
	{ key: 'productbarcodeform', url: '/productbarcodes/new?embedded&product=1', form: '#barcode-form', save: '#save-barcode-button' },
	{ key: 'quantityunitconversionform', url: '/quantityunitconversion/new?embedded&product=1', form: '#quconversion-form', save: '#save-quconversion-button' },
	{ key: 'recipeposform', url: '/recipe/1/pos/new?embedded', form: '#recipe-pos-form', save: '#save-recipe-pos-button' },
	{ key: 'userobjectform', url: '/userobject/{userentity}/new?embedded', form: '#userobject-form', save: '#save-userobject-button', needs: 'userentity' }
];

module.exports = { LISTS, LOAD_ONLY_LISTS, FORMS };
