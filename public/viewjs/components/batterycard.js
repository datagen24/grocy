// Implements the BatteryCard widget (views/components/batterycard.blade.php): a modal showing
// summary details for one battery (last charged, charge cycle count, edit/journal links).
// Public API: Refresh(batteryId) - fetches and renders the battery, also called by the
// ".batterycard-trigger" click handler below to populate the modal before showing it.
Victual.Components.BatteryCard = {};

/** Fetches battery details (GET batteries/{id}) and renders them into the #batterycard-* elements */
Victual.Components.BatteryCard.Refresh = function(batteryId)
{
	Victual.Api.Get('batteries/' + batteryId,
		function(batteryDetails)
		{
			$('#batterycard-battery-name').text(batteryDetails.battery.name);
			$('#batterycard-battery-used_in').text(batteryDetails.battery.used_in);
			$('#batterycard-battery-last-charged').text((batteryDetails.last_charged || __t('never')));
			$('#batterycard-battery-last-charged-timeago').attr("datetime", batteryDetails.last_charged || '');
			$('#batterycard-battery-charge-cycles-count').text((batteryDetails.charge_cycles_count || '0'));

			$('#batterycard-battery-edit-button').attr("href", U("/battery/" + batteryDetails.battery.id.toString()));
			$('#batterycard-battery-journal-button').attr("href", U("/batteriesjournal?embedded&battery=" + batteryDetails.battery.id.toString()));
			$('#batterycard-battery-edit-button').removeClass("disabled");
			$('#batterycard-battery-journal-button').removeClass("disabled");

			RefreshContextualTimeago(".batterycard");
		},
		function(xhr)
		{
			console.error(xhr);
		}
	);
};

// Any element with class "batterycard-trigger" and a "data-battery-id" attribute opens this card
$(document).on("click", ".batterycard-trigger", function(e)
{
	Victual.Components.BatteryCard.Refresh($(e.currentTarget).attr("data-battery-id"));
	$("#batterycard-modal").modal("show");
});
