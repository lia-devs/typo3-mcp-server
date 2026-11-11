<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['tx_testuntrackedtablefield_items'] = [
    'label' => 'List items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testuntrackedtablefield_item',
        'foreign_field' => 'parent_uid',
        'foreign_table_field' => 'parent_table',
        'foreign_sortby' => 'sorting',
    ],
];

$GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] =
    ($GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] ?? '') . ',tx_testuntrackedtablefield_items';
