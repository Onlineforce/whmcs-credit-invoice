<?php 
/**
 * A simple module that allows admins to credit / refund invoices.
 * 
 * The module adds buttons to the WHMCS invoice edit page. You can refund an invoice
 * and easily navigate between a it's credit note.
 * 
 * The module does this by duplicating an invoice, setting it's status to "Paid",
 * and then inverting all the amounts to negative. Finally it adds some data to both
 * the original invoice admin notes as well as the credit notes admin notes, this is
 * to be able to easily keep track of which credit note belongs to which invoice, and vise-versa.
 * 
 * @copyright Copyright (c) Onlineforce Sweden AB
 * @license GNU GPL v3 https://www.gnu.org/licenses/gpl-3.0.en.html
 */

if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}

require_once __DIR__ . '/functions.php';

function credit_invoice_config() {
	return array(
		'name' => 'Credit invoices',
		'description' => 'A simple module that allows admins to credit / refund invoices.',
		'author' => 'Onlineforce Sweden AB',
		'language' => 'english',
		'version' => '1.2',
}

function credit_invoice_activate() {

}

function credit_invoice_deactivate() {
	
};

function credit_invoice_output($vars) {

	$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);

	// Route POST actions to module functions.
	if (function_exists('credit_invoice_' . $action)) {
		call_user_func('credit_invoice_' . $action);
	} else {
		no_route_error();
	}

};

function no_route_error() {
	throw new Exception('No such action');
}