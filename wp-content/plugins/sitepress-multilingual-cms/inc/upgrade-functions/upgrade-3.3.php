<?php

global $wpdb;

$sql = "ALTER TABLE {$wpdb->prefix}icl_translation_status MODIFY COLUMN translation_package longtext NOT NULL";
$wpdb->query( $sql );

