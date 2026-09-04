<?php

// This is executed inside DatabaseMigrationService class/context

use Victual\Services\Database\StoredHtmlPurifier;
use Victual\Services\DatabaseService;

// Purifies rich text that predates the purifier.
//
// The five columns in BaseApiController::HTML_RENDERED_COLUMNS are rendered as HTML rather
// than escaped, so the boundary for them is the HTMLPurifier every API write goes through.
// Rows written before that existed - by upstream grocy, or by this fork before security
// sweep finding S1 - never met it, and nothing has rewritten them since. They still reach
// the raw Blade render in views/recipes.blade.php, the .html() renders in shoppinglist.js,
// equipment.js, productcard.js and chorecard.js, and summernote's own editable div.
//
// Once, here, rather than at every render: the sinks are deliberately raw and there are six
// of them, and a database is upgraded far less often than its descriptions are displayed.
// The identical routine runs after DatabaseImporter's copy, which is the other way a row
// can arrive without having met the purifier.
//
// Portable by construction: it is PHP and PDO, so it holds on both engines with one file.
$db = DatabaseService::GetInstance();

StoredHtmlPurifier::Purify($db->GetDbConnectionRaw(), $db->GetDialect());
