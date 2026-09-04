'use strict';

// The scenarios, in the order they run.
//
// **The order is part of the fixture.** Each scenario leaves rows behind, and the ones
// after it see them — the shopping-list scenario's derived queries read stock that earlier
// scenarios booked, which is the point, because a derived query over an empty table
// returns the same empty answer on any engine and proves nothing. Both instances run the
// same list in the same order, so both see the same accumulated state.
//
// Adding a scenario in the middle therefore changes what every later scenario sees. That
// is fine and is why they are numbered: append rather than insert, unless you mean it.

module.exports = [
	require('./00-system'),
	require('./10-entities'),
	require('./20-stock'),
	require('./21-barcodes-and-undo'),
	require('./30-shoppinglist'),
	require('./40-chores-batteries-tasks'),
	require('./50-recipes-mealplan'),
	require('./60-users-files-calendar')
];
