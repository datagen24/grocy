// View script for the about page (views/about.blade.php):
// changelog section collapsing and deep-linking to the changelog tab via the "tab" URI parameter

// Toggle the collapsible element following a "collapse-next" toggle link (used for the changelog release sections)
$('[data-toggle="collapse-next"]').on("click", function(e)
{
	e.preventDefault();
	$(this).parent().next().collapse("toggle");
});

// Open the changelog tab directly when requested via ?tab=changelog
if ((typeof GetUriParam("tab") !== "undefined" && GetUriParam("tab") === "changelog"))
{
	$(".nav-tabs a[href='#changelog']").tab("show");
}
