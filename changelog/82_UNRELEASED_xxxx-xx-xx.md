> ⚠️ Authentication middleware was reorganized, review your `AUTH_CLASS` setting (see the default reference in `config-dist.php` as usual)

> ⚠️ The API no longer sends `Access-Control-Allow-Origin: *`. Cross-origin browser access is now off unless the new `CORS_ALLOWED_ORIGINS` setting lists the origins that may use it

### New Feature: xxxx

- xxx

### Stock

- The product picker now searches product names accent insensitive
- Optimized the location input on the transfer page: The selected "From Location" is now automatically hidden in the "To Location" dropdown
- Fixed that the product picker workflow dialog was not displayed when the entered value contained double quotes
- Fixed that changing the location on the purchase page re-initialized the due date based on product defaults (if any)
- Fixed that when undoing a product consume or transfer transaction, the store of the corresponding stock entry wasn't restored
  - This will only apply to new consume / transfer transactions, not when undoing transactions made before using this release
- Fixed that the status filter on the master data products page always displayed "All" after selection (only affected Chrome/Edge)
- Fixed that the "This means _n QU_ will be removed/added from stock"-hint on the inventory page wasn't updated when changing the quantity unit only
- Fixed that the product open button on the stock overview page wasn't disabled after opening the last unit
- Fixed that when changing a product name to one that already exists, no corresponding error message was shown on the product edit page

### Shopping list

- Fixed that the shopping list setting (top right corner settings menu) "Round up quantity amounts to the nearest whole number" wasn't applied to shopping list item amounts where a quantity unit conversion was involved
- Fixed that printing the shopping list with "Group by product group" enabled created duplicated product group headlines in some cases
- Fixed that the total value at the top of the shopping list page wasn't updated after removing a shopping list item

### Recipes

- Fixed that the ingredient list showed fixed "Calories" instead of the configured `ENERGY_UNIT`

### Meal plan

- Fixed that "add recipe"-dropdown wasn't sorted alphabetically

### Chores

- Fixed that when tracking a chore via the context/more menu on the chores overview page, the chore name was missing in the confirmation popup

### Calendar

- xxx

### Tasks

- Added a table filter for "Assigned to"

### Batteries

- xxx

### Equipment

- xxx

### Userfields

- Fixed that Userfields of type "Select list (a single item can be selected)" changed by keyboard only were not saved

### General

- Fixed accent insensitive searching using the general table search field was broken
- Fixed that it wasn't possible to log in using passwords containing special escape sequences (e.g. `<<`)
- Fixed that the initially created location and quantity units weren't localized (only applies to new installations)

### API

- Fixed that the endpoints `POST /stock/shoppinglist/add-product` and `POST /stock/shoppinglist/remove-product` truncated decimal product amounts
- An unauthenticated API request is now answered with the usual `{ "error_message": ... }` body and `Content-Type: application/json` instead of a bodyless, untyped `401`
- A CORS preflight (`OPTIONS`) on an API route is now answered `204` instead of `401`, and carries the CORS headers when the request's `Origin` is listed in `CORS_ALLOWED_ORIGINS`
- Cross-origin responses no longer carry `Access-Control-Allow-Origin: *`. Set `CORS_ALLOWED_ORIGINS` to the exact origins that may call the API from a browser; the default is empty, which sends no CORS headers at all
- An unmatched `/api/...` path is now answered `404` instead of an empty `200`

### Server errors and logging

- Uncaught exceptions are now written to `stderr` as one line each, carrying the request method and path, the response status, the exception class and - when error details are enabled - the file, line and stack trace. In production nothing was recorded anywhere before this
- The server error page no longer shows the exception message, file, line, stack trace and system info to everyone. That block is now shown in `dev` mode only, and every value in it is HTML escaped
