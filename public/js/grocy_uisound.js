// UI sound effects: tiny wrappers around HTML5 Audio for the feedback sounds
// used by the barcode scanning / quick consume+purchase workflows.
// Not part of the global layout - only loaded by views which need it (consume, purchase).

Grocy.UISound = {};

/**
 * Plays the audio file at the given URL once.
 * @param {string} url Absolute URL of the sound file (use U() to build it)
 */
Grocy.UISound.Play = function(url)
{
	new Audio(url).play();
}

/**
 * Plays a silent sound to unlock audio playback -
 * browsers only allow audio after a user gesture, so this is called
 * from an input event handler to get the permission "for free".
 */
Grocy.UISound.AskForPermission = function()
{
	Grocy.UISound.Play(U("/uisounds/silence.mp3"));
}

/** Plays the "action succeeded" sound. */
Grocy.UISound.Success = function()
{
	Grocy.UISound.Play(U("/uisounds/success.mp3"));
}

/** Plays the "action failed" sound. */
Grocy.UISound.Error = function()
{
	Grocy.UISound.Play(U("/uisounds/error.mp3"));
}

/** Plays the beep sound acknowledging a recognized barcode scan. */
Grocy.UISound.BarcodeScannerBeep = function()
{
	Grocy.UISound.Play(U("/uisounds/barcodescannerbeep.mp3"));
}
