// View script for the login page (views/login.blade.php):
// focuses the username field, shows the "invalid credentials" hint and submits the login form.

setTimeout(function ()
{
	$('#username').focus();
}, Victual.FormFocusDelay);

// The server redirects back with ?invalid=true when the credentials were wrong

if (GetUriParam('invalid') === 'true')
{
	$('#login-error').text(__t('Invalid credentials, please try again'));
	$('#login-error').removeClass('d-none');
}

// Login form submit: the password is Base64 encoded into the hidden #password_base64 field
// (the plain text input is not part of the POSTed form)
$("#login-button").on("click", function (e)
{
	e.preventDefault();

	$("#password_base64").val(btoa($("#password_input").val()));
	$("#login-form").trigger("submit");
});
