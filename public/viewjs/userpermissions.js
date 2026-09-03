// Powers the user permissions form (userpermissions.blade.php): a nested tree of
// permission checkboxes (rendered recursively by the userpermission_select component,
// one "permission-sub-{permission_name}" container per node) where checking a parent
// permission implies and locks all its descendants.

// Checking/unchecking any permission checkbox cascades to its descendant checkboxes
$('input.permission-cb').click(
	function()
	{
		check_hierachy(this.checked, this.name);
	}
);

/**
 * Forces every descendant permission checkbox under the given parent permission name to
 * match (and, when checked, be disabled - since it's implied by the parent) the parent's
 * checked state.
 * @param {boolean} checked - the parent checkbox's new checked state
 * @param {string} name - the parent permission's name, matching its "permission-sub-{name}" container
 */
function check_hierachy(checked, name)
{
	var disabled = checked;
	$('#permission-sub-' + name).find('input.permission-cb')
		.prop('checked', disabled)
		.attr('disabled', disabled);
}

// Saves the effective permission set: only checkboxes that are checked AND not disabled
// (i.e. explicitly checked, not merely implied by a checked ancestor) are sent, since
// implied permissions are re-derived server-side from what's actually submitted
$('#permission-save').click(
	function()
	{
		var permission_list = $('input.permission-cb')
			.filter(function()
			{
				return $(this).prop('checked') && !$(this).attr('disabled');
			}).map(function()
			{
				return $(this).data('perm-id');
			}).toArray();

		Victual.Api.Put('users/' + Victual.EditObjectId + '/permissions', { 'permissions': permission_list },
			function(result)
			{
				toastr.success(__t("Permissions saved"));
			}
			// No error callback: this hand-rolled toastr.error was one of two in the tree
			// that re-implemented what Victual.Api.DefaultErrorHandler now does by default -
			// and did it worse, since it JSON.parses a response that a dropped connection
			// never produces, and renders a server-supplied message straight into an HTML
			// sink.
		);
	}
);

// Extra safety net when editing your own permissions: unchecking your own ADMIN
// permission asks for confirmation and reverts the checkbox if declined, so you can't
// accidentally lock yourself out
if (Victual.EditObjectId == Victual.UserId)
{
	$('input.permission-cb[name=ADMIN]').click(function()
	{
		var element = this;

		if (!element.checked)
		{
			bootbox.confirm({
				message: __t('Are you sure you want to remove full permissions for yourself?'),
				closeButton: false,
				buttons: {
					confirm: {
						label: __t('Yes'),
						className: 'btn-success'
					},
					cancel: {
						label: __t('No'),
						className: 'btn-danger'
					}
				},
				callback: function(result)
				{
					if (result == false)
					{
						element.checked = true;
						check_hierachy(element.checked, element.name);
					}
				}
			});
		}
	})
}
