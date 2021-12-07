BEGIN;

CREATE TABLE /*_*/access_control (
    ac_page_id int unsigned NOT NULL PRIMARY KEY,
    ac_tag_content blob NULL
)/*$wgDBTableOptions*/;

COMMIT;
