// Powers the user entity create/edit modal form (userentityform.blade.php).
// Victual.EditMode ('create'/'edit') and Victual.EditObjectId select POST vs PUT, both
// inside the shared form factory (public/js/victual_entity.js). Note: unlike most other
// forms here, this one has no userfields (a user entity IS the definition userfields
// attach to, so it can't itself carry them), hence userfields: false.

Victual.EntityForm({
	form: 'userentity-form',
	save: '#save-userentity-button',
	endpoint: 'objects/userentities',
	list: '/userentities',
	userfields: false,
	validateOnSelectChange: true
});

// Enable/disable the icon CSS class input depending on whether "show in sidebar menu" is on
$("#show_in_sidebar_menu").on("click", function ()
{
	if (this.checked)
	{
		$("#icon_css_class").removeAttr("disabled");
	}
	else
	{
		$("#icon_css_class").attr("disabled", "");
	}
});

// Click twice to trigger on-click but not change the actual checked state
$("#show_in_sidebar_menu").click();
$("#show_in_sidebar_menu").click();
