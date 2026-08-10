<?php
/**
 * Plain require-based bootstrap - no Composer needed.
 *
 *   require '/path/to/taxonfinder-php/autoload.php';
 *   $names = taxonfinder_find('Felis leo');
 *
 * (There is also a composer.json with a PSR-4 autoloader, if you prefer that.)
 */

require_once __DIR__ . '/src/Utility.php';
require_once __DIR__ . '/src/SortedIndex.php';
require_once __DIR__ . '/src/Dictionaries.php';
require_once __DIR__ . '/src/Parser.php';
require_once __DIR__ . '/src/Nomenclature.php';
require_once __DIR__ . '/src/Annotator.php';
require_once __DIR__ . '/src/NameTag.php';
require_once __DIR__ . '/src/Finder.php';
require_once __DIR__ . '/functions.php';
