// Powers the custom userfields list view (userfields.blade.php): lists userfields
// (optionally scoped to one entity via the "entity" URI param), with search/entity
// filters and deletion. The table, the search box and the delete confirmation are the
// shared list factory (public/js/victual_entity.js); the entity filter is this page's own
// and is reset alongside the search through the factory's onClear hook.

var userfieldsList = Victual.EntityList({
	table: '#userfields-table',
	list: '/userfields',
	onClear: function ()
	{
		$("#entity-filter").val("all");
		userfieldsList.Table.column(userfieldsList.Table.colReorder.transpose(1)).search("").draw();
	},
	delete: {
		button: '.userfield-delete-button',
		idAttr: 'data-userfield-id',
		nameAttr: 'data-userfield-name',
		endpoint: 'objects/userfields',
		message: 'Are you sure you want to delete user field "%s"?'
	}
});

// Entity filter, matched against the entity column (index 1); option value/text are both
// the entity's internal name, so it doubles as the search term and as the "entity" param
// pre-filled into the "add new userfield" button's link
$("#entity-filter").on("change", function ()
{
	var value = $("#entity-filter option:selected").text();
	if (value === __t("All"))
	{
		value = "";
	}

	userfieldsList.Table.column(userfieldsList.Table.colReorder.transpose(1)).search(value).draw();
	$("#new-userfield-button").attr("href", U("/userfield/new?embedded&entity=" + value));
});

// Pre-apply the entity filter when opened scoped to one
if (GetUriParam("entity"))
{
	$("#entity-filter").val(GetUriParam("entity"));
	$("#entity-filter").trigger("change");
	setTimeout(function ()
	{
		$("#name").focus();
	}, Victual.FormFocusDelay);
}
