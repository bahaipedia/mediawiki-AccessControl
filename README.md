# mediawiki-AccessControl

A fork of https://github.com/wikimedia/mediawiki-extensions-AccessControl, however mostly rewritten by https://github.com/pastakhov.

A note from the developer:

*I removed duplicate parts of the code and simplified the logic to make the code clearer.
also I replaced code that looks for accesscontrol tags because there is simpler way to get value of the tags. then I added the cache variables usage and stored value of accesscontrol tags to the database to be able to get it much faster.*

# Options:
$wgAccessControlAllowTextSnippetInSearchResultsForAll (if set to false page content is hidden in search results)

Requires wfLoadExtension('AccessControl'); to be set in LocalSettings.php

Run update.php after instillation for the table to be added to the database.
