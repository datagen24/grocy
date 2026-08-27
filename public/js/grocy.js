// Grocy global object core: API wrapper (Grocy.Api), translation helpers (__t/__n),
// generic frontend helpers (Grocy.FrontendHelpers), locale aware number/date display,
// iframe modal / cross-window messaging and various global UI wiring.
// Loaded on every page (after extensions.js) before the per-view script; the Grocy
// object itself (BaseUrl, UserSettings, UserId, Mode, ...) is pre-populated inline
// by views/layout/default.blade.php.

// Thin XMLHttpRequest wrapper around the grocy REST API,
// all apiFunction arguments are paths relative to /api (e.g. "stock/products/1")
Grocy.Api = {};

/**
 * Executes a GET request against the grocy API.
 * @param {string} apiFunction API path relative to /api, e.g. "system/db-changed-time"
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.Get = function (apiFunction, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/' + apiFunction);

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('GET', url, true);
	xhr.send();
};

/**
 * Executes a POST request (JSON body) against the grocy API.
 * @param {string} apiFunction API path relative to /api
 * @param {Object} jsonData Request body, sent as JSON
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.Post = function (apiFunction, jsonData, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/' + apiFunction);

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('POST', url, true);
	xhr.setRequestHeader('Content-Type', 'application/json');
	xhr.send(JSON.stringify(jsonData));
};

/**
 * Executes a PUT request (JSON body) against the grocy API.
 * @param {string} apiFunction API path relative to /api
 * @param {Object} jsonData Request body, sent as JSON
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.Put = function (apiFunction, jsonData, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/' + apiFunction);

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('PUT', url, true);
	xhr.setRequestHeader('Content-Type', 'application/json');
	xhr.send(JSON.stringify(jsonData));
};

/**
 * Executes a DELETE request against the grocy API.
 * @param {string} apiFunction API path relative to /api
 * @param {Object} jsonData Request body, sent as JSON (usually {})
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.Delete = function (apiFunction, jsonData, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/' + apiFunction);

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('DELETE', url, true);
	xhr.setRequestHeader('Content-Type', 'application/json');
	xhr.send(JSON.stringify(jsonData));
};

/**
 * Uploads a file via PUT /api/files/{group}/{fileName} (raw octet-stream body).
 * @param {Blob|File} file The file contents to upload
 * @param {string} group File group (server side subfolder, e.g. "productpictures", "recipepictures")
 * @param {string} fileName File name; is BASE64 encoded for the URL
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.UploadFile = function (file, group, fileName, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/files/' + group + '/' + btoa(fileName));

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('PUT', url, true);
	xhr.setRequestHeader('Content-Type', 'application/octet-stream');
	xhr.send(file);
};

/**
 * Deletes a file via DELETE /api/files/{group}/{fileName}.
 * @param {string} fileName File name; is BASE64 encoded for the URL
 * @param {string} group File group (server side subfolder)
 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
 */
Grocy.Api.DeleteFile = function (fileName, group, success, error)
{
	var xhr = new XMLHttpRequest();
	var url = U('/api/files/' + group + '/' + btoa(fileName));

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState === XMLHttpRequest.DONE)
		{
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					if (xhr.status === 200)
					{
						success(JSON.parse(xhr.responseText));
					}
					else
					{
						success({});
					}
				}
			}
			else
			{
				if (error)
				{
					error(xhr);
				}
			}
		}
	};

	xhr.open('DELETE', url, true);
	xhr.setRequestHeader('Content-Type', 'application/json');
	xhr.send();
};

/**
 * Turns a root relative path (e.g. "/api/stock" or "/css/grocy.css") into an
 * absolute URL by prepending the configured base URL (Grocy.BaseUrl).
 * @param {string} relativePath Path starting with "/"
 * @returns {string} Absolute URL
 */
U = function (relativePath)
{
	return Grocy.BaseUrl.replace(/\/$/, '') + relativePath;
}

// Gettext style translators; the localization strings are provided inline by the layout
// (TranslatorQu holds the separate quantity unit plural strings)
Grocy.Translator = new window.translator.default(Grocy.LocalizationStrings);
Grocy.TranslatorQu = new window.translator.default(Grocy.LocalizationStringsQu);

/**
 * Translates the given text into the current language,
 * placeholders are filled in sprintf-style (e.g. __t("Removed %1$s of %2$s", amount, name)).
 * In dev mode missing localizations are reported via POST /api/system/log-missing-localization.
 * @param {string} text Source (English) text / localization key
 * @param {...*} placeholderValues Values for sprintf placeholders in the translated text
 * @returns {string} Translated text
 */
__t = function (text, ...placeholderValues)
{
	if (!text)
	{
		return text;
	}

	if (Grocy.Mode === "dev")
	{
		var text2 = text;
		if (Grocy.LocalizationStrings && !Grocy.LocalizationStrings.messages[""].hasOwnProperty(text2))
		{
			Grocy.Api.Post('system/log-missing-localization', { "text": text2 });
		}
	}

	// sprintf can fail due to invalid placeholders
	try
	{
		return sprintf(Grocy.Translator.__(text, ...placeholderValues), ...placeholderValues);
	} catch (e)
	{
		return Grocy.Translator.__(text, ...placeholderValues);
	}
}
/**
 * Translates a singular/plural text based on the given number
 * (the number itself is provided to the "%s"/"%d" placeholder, locale formatted).
 * @param {number} number Amount deciding the plural form (absolute value is used)
 * @param {string} singularForm Source singular text
 * @param {string} pluralForm Source plural text (defaults to singularForm)
 * @param {boolean} [isQu=false] Use the quantity unit translator (TranslatorQu) instead of the general one
 * @returns {string} Translated text with the number filled in
 */
__n = function (number, singularForm, pluralForm, isQu = false)
{
	if (!singularForm)
	{
		return singularForm;
	}

	if (Grocy.Mode === "dev")
	{
		var singularForm2 = singularForm;
		if (Grocy.LocalizationStrings && !Grocy.LocalizationStrings.messages[""].hasOwnProperty(singularForm2))
		{
			Grocy.Api.Post('system/log-missing-localization', { "text": singularForm2 });
		}
	}

	if (!pluralForm)
	{
		pluralForm = singularForm;
	}

	number = Math.abs(number);

	if (isQu)
	{
		return sprintf(Grocy.TranslatorQu.n__(singularForm, pluralForm, number, number), number.toLocaleString());
	}
	else
	{
		return sprintf(Grocy.Translator.n__(singularForm, pluralForm, number, number), number.toLocaleString());
	}
}

/**
 * Renders all <time class="timeago" datetime="..."> elements below rootSelector
 * as relative time ("x days ago" via moment.fromNow()), "Today", or the special
 * labels "Never"/"Unknown" for the sentinel dates 2999-12-31 / 2888-12-31;
 * for elements with class "timeago-date-only" the preceding element's text is
 * truncated to the date part (first 10 characters, YYYY-MM-DD).
 * @param {string} [rootSelector="#page-content"] Selector to limit the processed subtree
 */
RefreshContextualTimeago = function (rootSelector = "#page-content")
{
	$(rootSelector + " time.timeago").each(function ()
	{
		var element = $(this);

		if (!element.hasAttr("datetime"))
		{
			element.text("");
			return;
		}

		var timestamp = element.attr("datetime");

		if (!timestamp || timestamp.length < 10)
		{
			element.text("");
			return;
		}

		if (!moment(timestamp).isValid())
		{
			element.text("");
			return;
		}

		var isNever = timestamp && timestamp.substring(0, 10) == "2999-12-31";
		var isUnknown = timestamp && timestamp.substring(0, 10) == "2888-12-31";
		var isToday = timestamp && timestamp.substring(0, 10) == moment().format("YYYY-MM-DD");
		var isDateWithoutTime = element.hasClass("timeago-date-only");

		if (isNever)
		{
			element.prev().text(__t("Never"));
			element.text("");
		}
		else if (isUnknown)
		{
			element.prev().text(__t("Unknown"));
			element.text("");
		}
		else if (isToday)
		{
			element.text(__t("Today"));
		}
		else
		{
			element.text(moment(timestamp).fromNow());
		}

		if (isDateWithoutTime)
		{
			element.prev().text(element.prev().text().substring(0, 10));
		}
	});
}
RefreshContextualTimeago();

// Global defaults for toastr notifications (used for all success/error toasts)
toastr.options = {
	toastClass: 'alert',
	closeButton: true,
	timeOut: 20000,
	extendedTimeOut: 5000
};

Grocy.FrontendHelpers = {};
Grocy.FrontendHelpers.ValidateForm = function (formId, reportValidity = false)
{
	var form = document.getElementById(formId);
	if (form === null || form === undefined)
	{
		return;
	}

	$(form).addClass('was-validated');

	if (reportValidity)
	{
		form.reportValidity();
	}

	return form.checkValidity();
}

Grocy.FrontendHelpers.BeginUiBusy = function (formId = null)
{
	$("body").addClass("cursor-busy");

	if (formId !== null)
	{
		$("#" + formId + " :input").attr("disabled", true);
	}
}

Grocy.FrontendHelpers.EndUiBusy = function (formId = null)
{
	$("body").removeClass("cursor-busy");

	if (formId !== null)
	{
		$("#" + formId + " :input").attr("disabled", false);
	}
}

Grocy.FrontendHelpers.ShowGenericError = function (message, exception)
{
	toastr.error(__t(message) + '<br><br>' + __t('Click to show technical details'), '', {
		onclick: function ()
		{
			var errorDetails = JSON.stringify(exception, null, 4);
			if (typeof exception === "object" && exception !== null && exception.hasOwnProperty("error_message"))
			{
				errorDetails = exception.error_message;
			}

			bootbox.alert({
				title: __t('Error details'),
				message: '<p class="text-monospace my-0">' + errorDetails + '</p>',
				closeButton: false,
				className: "wider"
			});
		}
	});

	console.error(exception);
}

Grocy.FrontendHelpers.SaveUserSetting = function (settingsKey, value, force = false)
{
	if (Grocy.UserSettings[settingsKey] == value && !force)
	{
		return;
	}

	Grocy.UserSettings[settingsKey] = value;

	jsonData = {};
	jsonData.value = value;
	Grocy.Api.Put('user/settings/' + settingsKey, jsonData,
		function (result)
		{
			// Nothing to do...
		},
		function (xhr)
		{
			console.error(xhr);
		}
	);
}

Grocy.FrontendHelpers.DeleteUserSetting = function (settingsKey, reloadPageOnSuccess = false)
{
	delete Grocy.UserSettings[settingsKey];

	Grocy.Api.Delete('user/settings/' + settingsKey, {},
		function (result)
		{
			if (reloadPageOnSuccess)
			{
				location.reload();
			}
		},
		function (xhr)
		{
			if (xhr.statusText)
			{
				Grocy.FrontendHelpers.ShowGenericError('Error while deleting, please retry', xhr.response)
			}
		}
	);
}

Grocy.FrontendHelpers.RunWebhook = function (webhook, data, repetitions = 1)
{
	Object.assign(data, webhook.extra_data);
	var hasAlreadyFailed = false;

	for (i = 0; i < repetitions; i++)
	{
		if (webhook.json)
		{
			$.ajax(webhook.hook, { "data": JSON.stringify(data), "contentType": "application/json", "type": "POST" }).fail(function (req, status, errorThrown)
			{
				if (!hasAlreadyFailed)
				{
					hasAlreadyFailed = true;
					Grocy.FrontendHelpers.ShowGenericError(__t("Error while executing WebHook", { "status": status, "errorThrown": errorThrown }));
				}
			});
		}
		else
		{
			$.post(webhook.hook, data).fail(function (req, status, errorThrown)
			{
				if (!hasAlreadyFailed)
				{
					hasAlreadyFailed = true;
					Grocy.FrontendHelpers.ShowGenericError(__t("Error while executing WebHook", { "status": status, "errorThrown": errorThrown }));
				}
			});
		}
	}
}

$(document).on("keyup paste change click", "input, select, textarea", function ()
{
	$(this).addClass("is-dirty").closest("form").addClass("is-dirty");
});

// Auto saving user setting controls
$(document).on("change", ".user-setting-control", function ()
{
	var element = $(this);
	var settingKey = element.attr("data-setting-key");

	if (!element[0].checkValidity())
	{
		return;
	}

	var inputType = "unknown";
	if (typeof element.attr("type") !== typeof undefined && element.attr("type") !== false)
	{
		inputType = element.attr("type").toLowerCase();
	}

	if (inputType === "checkbox")
	{
		value = element.is(":checked");
	}
	else
	{
		var value = element.val();
	}

	Grocy.FrontendHelpers.SaveUserSetting(settingKey, value);
});

// Show file name Bootstrap custom file input
$('input.custom-file-input').on('change', function ()
{
	$(this).next('.custom-file-label').html(GetFileNameFromPath($(this).val()));
});

// Translation of "Browse"-button of Bootstrap custom file input
if ($(".custom-file-label").length > 0)
{
	$("<style>").html('.custom-file-label::after { content: "' + __t("Select file") + '"; }').appendTo("head");
}

ResizeResponsiveEmbeds = function ()
{
	$("iframe.embed-responsive").each(function ()
	{
		var iframeBody = $(this)[0].contentWindow.document.body;
		if (iframeBody)
		{
			$(this).attr("height", iframeBody.scrollHeight.toString() + "px");
		}

		if ($("body").hasClass("fullscreen-card"))
		{
			$(this).attr("height", $("body").height().toString() + "px");
		}
	});

	var maxHeight = $("body").height() - $("#mainNav").outerHeight() - 62;
	if ($("body").hasClass("fullscreen-card"))
	{
		maxHeight = $("body").height();
	}
	$("embed.embed-responsive:not(.resize-done)").attr("height", maxHeight.toString() + "px").addClass("resize-done");
}
$(window).on("resize", function ()
{
	ResizeResponsiveEmbeds();
});
$("iframe").on("load", function ()
{
	ResizeResponsiveEmbeds();
});
$(document).on("shown.bs.modal", function (e)
{
	ResizeResponsiveEmbeds();
});
$(document).on("hidden.bs.modal", function (e)
{
	$("body").removeClass("fullscreen-card");
});
$("body").children().each(function (index, child)
{
	new ResizeObserver(function ()
	{
		window.parent.postMessage(WindowMessageBag("ResizeResponsiveEmbeds"), Grocy.BaseUrl);
	}).observe(child);
});

function WindowMessageBag(message, payload = null)
{
	var obj = {};
	obj.Message = message;
	obj.Payload = payload;
	return obj;
}

// Add border around anchor link section
if (window.location.hash)
{
	$(window.location.hash).addClass("p-2 border border-info rounded");
}

function RefreshLocaleNumberDisplay(rootSelector = "#page-content")
{
	$(rootSelector + " .locale-number.locale-number-currency:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { style: "currency", currency: Grocy.Currency, minimumFractionDigits: Grocy.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_prices_display }));
		element.addClass("number-parsing-done");
	});

	$(rootSelector + " .locale-number.locale-number-quantity-amount:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_amounts }));
		element.addClass("number-parsing-done");
	});

	$(rootSelector + " .locale-number.locale-number-generic:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }));
		element.addClass("number-parsing-done");
	});
}
RefreshLocaleNumberDisplay();
$(".locale-number").each(function ()
{
	new MutationObserver(function (mutations)
	{
		mutations.forEach(mutation =>
		{
			if (mutation.type == "childList" || mutation.type == "attributes")
			{
				$(mutation.target).removeClass("number-parsing-done");
			}
		});
	}).observe(this, {
		attributes: true,
		childList: true,
		subtree: true
	});
});

function RefreshLocaleNumberInput(rootSelector = "#page-content")
{
	$(rootSelector + " .locale-number-input.locale-number-currency").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(Number.parseFloat(value).toLocaleString("en", { minimumFractionDigits: Grocy.UserSettings.stock_decimal_places_prices_input, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_prices_input, useGrouping: false }));
	});

	$(rootSelector + " .locale-number-input.locale-number-quantity-amount").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(Number.parseFloat(value).toLocaleString("en", { minimumFractionDigits: 0, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_amounts, useGrouping: false }));
	});

	$(rootSelector + " .locale-number-input.locale-number-generic").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(value.toLocaleString("en", { minimumFractionDigits: 0, maximumFractionDigits: 2, useGrouping: false }));
	});
}
RefreshLocaleNumberInput();

$(document).on("click", ".easy-link-copy-textbox", function ()
{
	$(this).select();
});

if (Grocy.CalendarFirstDayOfWeek)
{
	moment.updateLocale(moment.locale(), {
		"week": {
			"dow": Number.parseInt(Grocy.CalendarFirstDayOfWeek)
		}
	});
}

if (GetUriParam("embedded"))
{
	$("body").append('<div class="fixed-top" style="left: unset;"> \
		<button class="btn btn-light btn-sm close-last-modal-button" \
			type="button" \> \
			<i class="fa-solid fa-xmark"></i> \
		</button> \
	</div>');
}

$(document).on("click", ".close-last-modal-button", function ()
{
	window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
});

$("body").on("keydown", function (e)
{
	if (e.key == "Escape")
	{
		window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
	}
});


$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message == "ShowSuccessMessage")
	{
		toastr.success(data.Payload);
	}
	else if (data.Message == "CloseLastModal")
	{
		$(".modal:visible").not(".custom-escape-key-handling").last().modal("hide");
	}
	else if (data.Message == "ResizeResponsiveEmbeds")
	{
		ResizeResponsiveEmbeds();
	}
	else if (data.Message == "IframeModal")
	{
		IframeModal(data.Payload.Link, data.Payload.DialogType);
	}
	else if (data.Message == "Reload")
	{
		window.location.reload();
	}
	else if (data.Message == "BroadcastMessage")
	{
		// data.Payload is the original WindowMessageBag

		// => Send the original message to this window
		window.postMessage(data.Payload, Grocy.BaseUrl);

		// => Bubble the broadcast message down to all child iframes
		$("iframe.embed-responsive").each(function ()
		{
			$(this)[0].contentWindow.postMessage(data, Grocy.BaseUrl);
		});
	}
});

window.IsGrocy = true;
Grocy.GetTopmostWindow = function ()
{
	if (window.top.IsGrocy)
	{
		// If the top window is Grocy (so when we're currently not running in an iframe) return that immediately
		return window.top;
	}
	else
	{
		// Otherwise, so when we're currently running in an iframe, climb up the window chain and check for the top most Grocy window
		var topmostGrocyWindow = window;

		var currentWindow = window;
		while (currentWindow != window.top)
		{
			if (currentWindow.IsGrocy)
			{
				topmostGrocyWindow = currentWindow;
			}

			currentWindow = currentWindow.parent;
		}

		return topmostGrocyWindow;
	}
}

$(document).on("click", ".show-as-dialog-link", function (e)
{
	e.preventDefault();

	var element = $(e.currentTarget);
	var link = element.attr("href");

	var dialogType = "form";
	if (element.hasAttr("data-dialog-type"))
	{
		dialogType = element.attr("data-dialog-type")
	}

	if (Grocy.GetTopmostWindow() != window.self)
	{
		Grocy.GetTopmostWindow().postMessage(WindowMessageBag("IframeModal", { "Link": link, "DialogType": dialogType }), Grocy.BaseUrl);
	}
	else
	{
		IframeModal(link, dialogType);
	}
});

function IframeModal(link, dialogClass = "form")
{
	bootbox.dialog({
		message: '<iframe class="embed-responsive" src="' + link + '"></iframe>',
		size: 'large',
		backdrop: true,
		closeButton: false,
		className: dialogClass
	});
}

// Init Bootstrap tooltips
$('[data-toggle="tooltip"]').tooltip();

// serializeJSON defaults
$.serializeJSON.defaultOptions.checkboxUncheckedValue = "0";

// bootstrap-combobox defaults
BootstrapComboboxDefaults = {
	"appendId": "_text_input",
	"iconCaret": "fa-solid fa-caret-down",
	"iconRemove": "fa-solid fa-xmark",
	"matcher": function (item)
	{
		return ~item.accentNeutralise().toLowerCase().indexOf(this.query.accentNeutralise().toLowerCase());
	}
};

$(Grocy.UserPermissions).each(function (index, item)
{
	if (item.has_permission == 0)
	{
		$('.permission-' + item.permission_name).addClass('disabled').addClass('not-allowed');
	}
});

$('a.link-return').not(".btn").each(function ()
{
	var base = $(this).data('href');
	if (base.contains('?'))
	{
		$(this).attr('href', base + '&returnto' + encodeURIComponent(Grocy.CurrentUrlRelative));
	}
	else
	{
		$(this).attr('href', base + '?returnto=' + encodeURIComponent(Grocy.CurrentUrlRelative));
	}

});
$(document).on("click", "a.btn.link-return", function (e)
{
	e.preventDefault();

	var link = GetUriParam("returnto");
	if (!link || !link.length > 0)
	{
		location.href = $(e.currentTarget).attr("href");
	}
	else
	{
		location.href = U(link);
	}
});

$('.dropdown-item').has('.form-check input[type=checkbox]').on('click', function (e)
{
	if ($(e.target).is('div.form-check') || $(e.target).is('div.dropdown-item'))
	{
		$(e.target).find('input[type=checkbox]').click();
	}
});

$('[data-toggle="tooltip"][data-html="true"]').on("shown.bs.tooltip", function ()
{
	RefreshLocaleNumberDisplay(".tooltip");
})

$(document).on("click", '.btn, a, button', function (e)
{
	// Remove focus and hide any tooltips after click
	document.activeElement.blur();
	$(".tooltip").tooltip("hide");
});

// Delay only initial field focus
Grocy.FormFocusDelay = 500;
setTimeout(function ()
{
	Grocy.FormFocusDelay = 0;
}, 1000);
