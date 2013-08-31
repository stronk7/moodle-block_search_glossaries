<?php
// This file is part of block_search_glossaries,
// a contrib block for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    block_search_glossaries
 * @copyright  2005 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2013083100; // The current block version (Date: YYYYMMDDXX)
$plugin->requires  = 2012062500; // Requires this Moodle version (v2.3.0)
$plugin->component = 'block_search_glossaries';

$plugin->maturity = MATURITY_ALPHA;

$plugin->dependencies = array(
    'mod_glossary' => 2012061700); // Requires mod_glossary version (v2.3.0)