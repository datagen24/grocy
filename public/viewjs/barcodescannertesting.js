// View script for the barcode scanner testing page (views/barcodescannertesting.blade.php):
// compares scanned barcodes against an expected value and keeps hit/miss counters (no API calls involved)

// Global hit/miss counters for this testing session
Grocy.BarCodeScannerTestingHitCount = 0;
Grocy.BarCodeScannerTestingMissCount = 0;

// Evaluate the scanned barcode when the input loses focus (e.g. after a hardware scanner "typed" it)
$("#scanned_barcode").on("blur", function(e)
{
	OnBarcodeScanned($("#scanned_barcode").val());
});

// Enter in the scanned barcode field also triggers evaluation (scanners usually send Enter as suffix)
$("#scanned_barcode").keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		OnBarcodeScanned($("#scanned_barcode").val());
	}
});

// Only enable the scan input and camera scanner button once an expected barcode was entered
$("#expected_barcode").on("keyup", function(e)
{
	if ($("#expected_barcode").val().length > 1)
	{
		$("#scanned_barcode").removeAttr("disabled");
		$("#camerabarcodescanner-start-button").removeAttr("disabled");
		$("#camerabarcodescanner-start-button").removeClass("disabled");
	}
	else
	{
		$("#scanned_barcode").attr("disabled", "");
		$("#camerabarcodescanner-start-button").attr("disabled", "");
		$("#camerabarcodescanner-start-button").addClass("disabled");
	}
});

// Initial state: camera scanner disabled, focus the expected barcode input
setTimeout(function()
{
	$("#camerabarcodescanner-start-button").attr("disabled", "");
	$("#camerabarcodescanner-start-button").addClass("disabled");
	$("#expected_barcode").focus();
}, Grocy.FormFocusDelay);

// Prefill the expected barcode from the "barcode" URI parameter and jump straight to scanning
if (GetUriParam("barcode") !== undefined)
{
	$("#expected_barcode").val(GetUriParam("barcode"));
	setTimeout(function()
	{
		$("#expected_barcode").keyup();
		$("#scanned_barcode").focus();
	}, Grocy.FormFocusDelay);
}

/**
 * Compares the given barcode against #expected_barcode, updates the hit/miss counter
 * and prepends the scanned code (color-coded) to the #scanned_codes list.
 * @param {string} barcode The scanned barcode value
 */
function OnBarcodeScanned(barcode)
{
	if (barcode.length === 0)
	{
		return;
	}

	var bgClass = "";
	if (barcode != $("#expected_barcode").val())
	{
		Grocy.BarCodeScannerTestingMissCount++;
		bgClass = "bg-danger";

		$("#miss-count").text(Grocy.BarCodeScannerTestingMissCount);
		animateCSS("#miss-count", "flash");
	}
	else
	{
		Grocy.BarCodeScannerTestingHitCount++;
		bgClass = "bg-success";

		$("#hit-count").text(Grocy.BarCodeScannerTestingHitCount);
		animateCSS("#hit-count", "flash");
	}

	$("#scanned_codes").prepend("<option class='" + bgClass + "'>" + barcode + "</option>");
	setTimeout(function()
	{
		$("#scanned_barcode").val("");

		if (!$(":focus").is($("#expected_barcode")))
		{
			$("#scanned_barcode").focus();
		}
	}, Grocy.FormFocusDelay);
}

// Camera barcode scanner hook: handle scans coming from the camera scanner component targeted at #scanned_barcode
$(document).on("Grocy.BarcodeScanned", function(e, barcode, target)
{
	if (target !== "#scanned_barcode")
	{
		return;
	}

	OnBarcodeScanned(barcode);
});
