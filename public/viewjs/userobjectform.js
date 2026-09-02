// Powers the create/edit form for a single record ("userobject") of a custom user
// entity (userobjectform.blade.php). A userobject has no fields of its own beyond its
// entity link - all visible inputs are userfields, so the form body itself is just the
// (relabeled) userfields form; Victual.EditObjectParentId/-Name identify the owning entity.
// The save/validate/userfields cycle is the shared form factory
// (public/js/victual_entity.js), which is also where this form's missing Enter-to-submit
// handler comes back from: it was the one form page in the tree without one, and the
// factory binds it by construction rather than by anyone noticing.

Victual.EntityForm({
	form: 'userobject-form',
	save: '#save-userobject-button',
	endpoint: 'objects/userobjects',
	list: function ()
	{
		return '/userobjects/' + Victual.EditObjectParentName;
	},
	// The object itself carries nothing but its link to the parent entity - everything a
	// userobject holds is a userfield, saved separately by the factory afterwards.
	body: function ()
	{
		return { userentity_id: Victual.EditObjectParentId };
	},
	validateOnLoad: false,
	focus: null
});

// Strip the userfields form's usual "boxed section" styling and heading, since here it
// IS the whole form rather than an addendum to one
$("#userfields-form").removeClass("border").removeClass("border-info").removeClass("p-2").find("h2").addClass("d-none");

setTimeout(function ()
{
	$(".userfield-input").first().focus();
}, Victual.FormFocusDelay);
