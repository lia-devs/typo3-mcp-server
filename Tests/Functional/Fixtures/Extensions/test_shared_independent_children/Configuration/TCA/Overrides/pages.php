<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['pages']['columns']['tx_testsharedindependentchildren_items'] = [
    'label' => 'Shared items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testsharedindependentchildren_item',
        'foreign_field' => 'parent_uid',
        'foreign_table_field' => 'parent_table',
        'foreign_sortby' => 'sorting',
    ],
];

$GLOBALS['TCA']['pages']['types']['1']['showitem'] =
    ($GLOBALS['TCA']['pages']['types']['1']['showitem'] ?? '') . ',tx_testsharedindependentchildren_items';
