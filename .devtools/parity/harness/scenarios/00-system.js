'use strict';

// The endpoints that describe the instance rather than its data.
//
// `/system/info` and `/system/config` are the two that a client reads before it does
// anything else, so a difference here breaks every ecosystem client at once — which is
// what plan 17 is about. The version fields are masked by normalisation (the fork is
// renamed, plan 16); the *shape* around them is not, and that is what this checks.

module.exports = {
	name: 'system',
	tags: ['system'],

	async run(api) {
		await api.get('/system/info', { label: 'system info' });

		// Not `/system/time` without an offset as well: the endpoint takes one, and the
		// offset arithmetic is the only part of it that can be wrong in an interesting way.
		// The timestamp fields themselves are masked.
		await api.get('/system/time', { label: 'system time' });
		await api.get('/system/time?offset=3600', { label: 'system time, offset' });

		// The exposed-settings allowlist. SystemApiController::EXPOSED_SETTINGS is what
		// keeps MQTT_PASSWORD and INFLUXDB_TOKEN out of this response, so a fork that adds
		// settings and forgets the allowlist leaks them here — and this instance is
		// configured with both, so the check has something to find.
		await api.get('/system/config', { label: 'system config' });

		await api.get('/system/db-changed-time', { label: 'db changed time' });

		// A locale that exists on both, so a difference is about the strings rather than
		// about one side having a translation the other does not.
		await api.get('/system/localization-strings?lang=en', { label: 'localization strings, en' });
	}
};
