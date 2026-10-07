<?php
require_once "scs_header.php";
$forms->title("Product Search");

$menu->head();
print $forms->message();
?>
<form name="productSearch" id="productSearch" method="get" action="/products.php?action=search" accept-charset="utf-8" autocomplete="on">
<input type="hidden" name="search_source" id="search_source" value="term">
<input type="text" name="search_code" id="ajax_inventory_search" placeholder="Product Search" class="ui-autocomplete-input" autocomplete="off">
<button type="submit"><img src="images/red-magnifier.png"></button>
</form>
<?
$menu->copyright();
?>