// View script for the interactive REST API documentation page (views/openapiui.blade.php):
// boots Swagger UI against grocy's OpenAPI specification.

/**
 * Swagger UI plugin that hides the default top bar (URL input / explore bar).
 * @returns {Object} Swagger UI plugin object replacing the Topbar component with nothing
 */
function HideTopbarPlugin()
{
	return {
		components: {
			Topbar: function() { return null }
		}
	}
}

// Swagger UI setup - Grocy.OpenApi.SpecUrl (provided by the Blade template) points to the served openapi.json
const swaggerUi = SwaggerUIBundle({
	url: Grocy.OpenApi.SpecUrl,
	dom_id: '#swagger-ui',
	deepLinking: true,
	presets: [
		SwaggerUIBundle.presets.apis,
		SwaggerUIStandalonePreset
	],
	plugins: [
		SwaggerUIBundle.plugins.DownloadUrl,
		HideTopbarPlugin
	],
	layout: 'StandaloneLayout',
	docExpansion: "list",
	defaultModelsExpandDepth: -1,
	validatorUrl: false
});

window.ui = swaggerUi;
