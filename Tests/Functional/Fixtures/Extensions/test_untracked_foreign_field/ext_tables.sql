CREATE TABLE tx_testuntrackedforeignfield_item (
    title varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT '0' NOT NULL,
    content_element int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    tx_testuntrackedforeignfield_items int(11) unsigned DEFAULT '0' NOT NULL
);
