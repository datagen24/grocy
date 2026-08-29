// UI sound effects: tiny wrappers around HTML5 Audio for the feedback sounds
// used by the barcode scanning / quick consume+purchase workflows.
// Not part of the global layout - only loaded by views which need it (consume, purchase).

Victual.UISound = {};

/**
 * Plays the audio file at the given URL once.
 * @param {string} url Absolute URL of the sound file (use U() to build it)
 */
Victual.UISound.Play = function(url)
{
	new Audio(url).play();
}

/**
 * Plays a silent sound to unlock audio playback -
 * browsers only allow audio after a user gesture, so this is called
 * from an input event handler to get the permission "for free".
 */
Victual.UISound.AskForPermission = function()
{
	Victual.UISound.Play(U("/uisounds/silence.mp3"));
}

/** Plays the "action succeeded" sound. */
Victual.UISound.Success = function()
{
	Victual.UISound.Play(U("/uisounds/success.mp3"));
}

/** Plays the "action failed" sound. */
Victual.UISound.Error = function()
{
	Victual.UISound.Play(U("/uisounds/error.mp3"));
}

/** Plays the beep sound acknowledging a recognized barcode scan. */
Victual.UISound.BarcodeScannerBeep = function()
{
	Victual.UISound.Play(U("/uisounds/barcodescannerbeep.mp3"));
}
