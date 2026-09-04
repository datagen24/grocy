// Generic helper extensions: String/jQuery prototype additions and small global
// utility functions (URI parameter handling, array searching, debouncing, etc.)
// used throughout the frontend; loaded on every page before all other Victual scripts.

/**
 * Clears the text of the given element when it exactly equals the given text.
 * @param {string} selector jQuery selector
 * @param {string} text Text to compare against
 */
EmptyElementWhenMatches = function (selector, text)
{
	if ($(selector).text() === text)
	{
		$(selector).text('');
	}
};

/** Case insensitive "contains" check. @returns {boolean} */
String.prototype.contains = function (search)
{
	return this.toLowerCase().indexOf(search.toLowerCase()) !== -1;
};

/**
 * Replaces all occurrences of search (treated as a RegExp pattern) with replacement.
 * Note: This shadows the native String.replaceAll (regex semantics differ).
 */
String.prototype.replaceAll = function (search, replacement)
{
	return this.replace(new RegExp(search, "g"), replacement);
};

/** Escapes HTML special characters (&, <, >, ", ', /, `, =) as entities. */
String.prototype.escapeHTML = function ()
{
	return this.replace(/[&<>"'`=\/]/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;' })[s]);;
};

// E.g. "Crème fraîche" becomes "Creme fraiche"
String.prototype.accentNeutralise = function ()
{
	return this.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
};

// E.g. "<div class='c'>test</div>" becomes "test"
String.prototype.stripHtml = function ()
{
	// Repeated until it no longer changes: a single pass leaves a tag behind for
	// nested/overlapping input such as "<<a>b>". Not a security sanitiser - this only
	// normalises HTML cell content for DataTables search and sort (see victual_datatables.js).
	var result = String(this);
	var previous;

	do
	{
		previous = result;
		result = result.replace(/<[^<>]*>/g, '');
	} while (result !== previous);

	return result;
};

/**
 * Returns the value of the given query string parameter of the current URL.
 * @param {string} key Parameter name
 * @returns {string|boolean|undefined} Decoded value; true for a valueless parameter (?key); undefined when absent
 */
GetUriParam = function (key)
{
	var currentUri = window.location.search.substring(1);
	var vars = currentUri.split('&');

	for (i = 0; i < vars.length; i++)
	{
		var currentParam = vars[i].split('=');

		if (currentParam[0] === key)
		{
			return currentParam[1] === undefined ? true : decodeURIComponent(currentParam[1]);
		}
	}
};

/** Sets/replaces a query string parameter of the current URL (without reloading, via history.replaceState). */
UpdateUriParam = function (key, value)
{
	var queryParameters = new URLSearchParams(location.search);
	queryParameters.set(key, value);
	window.history.replaceState({}, "", decodeURIComponent(`${location.pathname}?${queryParameters}`));
};

/** Removes a query string parameter from the current URL (without reloading, via history.replaceState). */
RemoveUriParam = function (key)
{
	var queryParameters = new URLSearchParams(location.search);
	queryParameters.delete(key);
	window.history.replaceState({}, "", decodeURIComponent(`${location.pathname}?${queryParameters}`));
};

/**
 * Loose boolean conversion: true for true/"true"/"1"/"on" (case insensitive), false otherwise.
 * Useful for user settings, which are often stored as "0"/"1" strings.
 * @param {*} test Any value
 * @returns {boolean}
 */
BoolVal = function (test)
{
	if (!test)
	{
		return false;
	}

	var anything = test.toString().toLowerCase();
	if (anything === true || anything === "true" || anything === "1" || anything === "on")
	{
		return true;
	}
	else
	{
		return false;
	}
}

/** Returns the file name portion of a path (handles both / and \ separators). */
GetFileNameFromPath = function (path)
{
	return path.split("/").pop().split("\\").pop();
}

/** Returns the file extension (text after the last dot) of a path or file name. */
GetFileExtension = function (pathOrFileName)
{
	return pathOrFileName.split(".").pop();
}

// Custom jQuery selector :contains_case_insensitive("text") - like :contains, but case insensitive
$.extend($.expr[":"],
	{
		"contains_case_insensitive": function (elem, i, match, array)
		{
			return (elem.textContent || elem.innerText || "").toLowerCase().indexOf((match[3] || "").toLowerCase()) >= 0;
		}
	});

/**
 * Returns the first object in the array whose property matches (loose ==) the given value.
 * @returns {Object|null} The found object or null
 */
FindObjectInArrayByPropertyValue = function (array, propertyName, propertyValue)
{
	for (var i = 0; i < array.length; i++)
	{
		if (array[i][propertyName] == propertyValue)
		{
			return array[i];
		}
	}

	return null;
}

/**
 * Returns all objects in the array whose property matches (loose ==) the given value.
 * @returns {Object[]} Possibly empty array of matches
 */
FindAllObjectsInArrayByPropertyValue = function (array, propertyName, propertyValue)
{
	var returnArray = [];

	for (var i = 0; i < array.length; i++)
	{
		if (array[i][propertyName] == propertyValue)
		{
			returnArray.push(array[i]);
		}
	}

	return returnArray;
}

/** jQuery plugin: whether the (first) element has the given attribute. @returns {boolean} */
$.fn.hasAttr = function (name)
{
	return this.attr(name) !== undefined;
};

/** Whether the given text is parseable as JSON. @returns {boolean} */
function IsJsonString(text)
{
	try
	{
		JSON.parse(text);
	} catch (e)
	{
		return false;
	}
	return true;
}

/**
 * Debounce: returns a wrapper which delays calling callable until
 * delayMilliseconds passed without a new invocation (used e.g. for search inputs).
 * @param {Function} callable Function to debounce
 * @param {number} delayMilliseconds Delay in milliseconds
 * @returns {Function} Debounced wrapper
 */
function Delay(callable, delayMilliseconds)
{
	var timer = 0;
	return function ()
	{
		var context = this;
		var args = arguments;

		clearTimeout(timer);
		timer = setTimeout(function ()
		{
			callable.apply(context, args);
		}, delayMilliseconds || 0);
	};
}

/**
 * jQuery plugin: whether the element is (partially) visible in the current viewport.
 * @param {number} [extraHeightPadding=0] Extra top offset to tolerate (e.g. a fixed header height)
 * @returns {boolean}
 */
$.fn.isVisibleInViewport = function (extraHeightPadding = 0)
{
	var elementTop = $(this).offset().top;
	var viewportTop = $(window).scrollTop() - extraHeightPadding;

	return elementTop + $(this).outerHeight() > viewportTop && elementTop < viewportTop + $(window).height();
};

/**
 * Runs an Animate.css animation on the matched elements and cleans the classes up afterwards.
 * @param {string} selector jQuery selector
 * @param {string} animationName Animate.css animation class (e.g. "flash")
 * @param {Function} [callback] Called once the animation finished
 * @param {string} [speed="faster"] Animate.css speed class
 */
function animateCSS(selector, animationName, callback, speed = "faster")
{
	var nodes = $(document).find(selector);
	nodes.addClass('animated').addClass(speed).addClass(animationName);

	function handleAnimationEnd()
	{
		nodes.removeClass('animated').removeClass(speed).removeClass(animationName);
		nodes.unbind('animationend', handleAnimationEnd);

		if (typeof callback === 'function')
		{
			callback();
		}
	}

	nodes.on('animationend', handleAnimationEnd);
}

/** Returns a random alphanumeric string (not cryptographically secure). */
function RandomString()
{
	return Math.random().toString(36).substring(2, 100) + Math.random().toString(36).substring(2, 100);
}

/**
 * Renders the given text as a QR code (via bwip-js) and returns it as <img> HTML.
 * @param {string} text Text to encode
 * @returns {string} HTML of an <img class="qr-code"> element with a data URI source
 */
function QrCodeImgHtml(text)
{
	var dummyCanvas = document.createElement("canvas");
	var img = document.createElement("img");

	bwipjs.toCanvas(dummyCanvas, {
		bcid: "qrcode",
		text: text,
		scale: 4,
		includetext: false
	});
	img.src = dummyCanvas.toDataURL("image/png");
	img.classList.add("qr-code");

	return img.outerHTML;
}

/**
 * Sanitizes a file name for storage: transliterates German umlauts,
 * strips whitespace and removes any non-ASCII character.
 * @param {string} fileName Original file name
 * @returns {string} Cleaned file name
 */
function CleanFileName(fileName)
{
	// Umlaute seem to cause problems on Linux...
	fileName = fileName.toLowerCase().replaceAll(/ä/g, 'ae').replaceAll(/ö/g, 'oe').replaceAll(/ü/g, 'ue').replaceAll(/ß/g, 'ss');

	// Multiple spaces seem to be a problem, so simply strip them all
	fileName = fileName.replace(/\s+/g, "");

	// Remove any non-ASCII character
	fileName = fileName.replace(/[^\x00-\x7F]/g, "");

	return fileName;
}

/** Converts line breaks in the given string to <br> tags (null/undefined become ""). */
function nl2br(s)
{
	if (s == null || s === undefined)
	{
		return "";
	}

	return s.replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, "$1<br>$2");
}
