CREATE TABLE tx_testsharedindependentchildren_item (
    title varchar(255) DEFAULT '' NOT NULL,
    parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    parent_table varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tt_content (
    tx_testsharedindependentchildren_items int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE pages (
    tx_testsharedindependentchildren_items int(11) unsigned DEFAULT '0' NOT NULL
);
